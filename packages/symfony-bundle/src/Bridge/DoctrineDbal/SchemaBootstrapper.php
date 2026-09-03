<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DoctrineDbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaConfig;
use Doctrine\DBAL\Schema\Table;

/**
 * Creates or updates the tables the SDK's Doctrine DBAL storage adapters need.
 *
 * Public because an adopter has to run it from wherever its platform installs things, and that
 * is usually outside the request lifecycle: `SwagAgenticCommerce` calls it from the Shopware
 * plugin's `install()` and `update()` hooks, before the plugin is active and before the
 * container it would otherwise resolve this from exists.
 *
 * That also makes it the one class here whose contract covers *when* it may be called, not just
 * how: it must stay idempotent and additive, because it runs again on every plugin update
 * against storage that already has data. A change that is safe on an empty schema and not on a
 * populated one breaks installations rather than requests.
 */
final class SchemaBootstrapper
{
    private StorageSchemaDefinition $schemaDefinition;

    public function __construct(
        private Connection $connection,
        ?StorageSchemaDefinition $schemaDefinition = null,
    ) {
        $this->schemaDefinition = $schemaDefinition ?? new StorageSchemaDefinition();
    }

    public function ensureSchema(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        // Introspect only the SDK's own tables. A plain introspectSchema() reads every table, so a
        // foreign column type the platform cannot map (e.g. MySQL `enum`) would throw "Unknown
        // database type ... requested" before our tables are reached.
        $currentSchema = $this->introspectOwnedSchema($schemaManager);
        $desiredSchema = $this->schemaDefinition->createSchema($schemaManager->createSchemaConfig(), $currentSchema->getNamespaces());
        // Compare only SDK-owned tables so application tables missing from the desired SDK schema stay untouched.
        $currentSdkSchema = $this->createComparableCurrentSchema(
            $schemaManager->createSchemaConfig(),
            $currentSchema,
            $desiredSchema,
        );

        $schemaDiff = $schemaManager->createComparator()->compareSchemas($currentSdkSchema, $desiredSchema);
        foreach ($this->connection->getDatabasePlatform()->getAlterSchemaSQL($schemaDiff) as $statement) {
            $this->connection->executeStatement($statement);
        }
    }

    /**
     * Introspects the live schema with a temporary filter that limits it to the SDK's own
     * tables, restoring the connection's previous filter afterwards. Tables outside the SDK
     * are never reflected, so unmapped column types elsewhere in the database cannot break it.
     *
     * @param AbstractSchemaManager<\Doctrine\DBAL\Platforms\AbstractPlatform> $schemaManager
     */
    private function introspectOwnedSchema(AbstractSchemaManager $schemaManager): Schema
    {
        $ownedTables = $this->schemaDefinition->tableNames();

        $configuration = $this->connection->getConfiguration();
        $previousFilter = $configuration->getSchemaAssetsFilter();
        $configuration->setSchemaAssetsFilter(
            static function ($asset) use ($ownedTables): bool {
                $name = $asset instanceof AbstractAsset ? $asset->getName() : (string) $asset;
                $separatorPosition = strrpos($name, '.');
                if ($separatorPosition !== false) {
                    $name = substr($name, $separatorPosition + 1);
                }

                return in_array(strtolower($name), $ownedTables, true);
            },
        );

        try {
            return $schemaManager->introspectSchema();
        } finally {
            $configuration->setSchemaAssetsFilter($previousFilter);
        }
    }

    private function createComparableCurrentSchema(
        SchemaConfig $schemaConfig,
        Schema $currentSchema,
        Schema $desiredSchema,
    ): Schema {
        // Clone only objects that the SDK owns; unrelated tables and sequences are invisible to the diff.
        $tables = [];
        foreach ($currentSchema->getTables() as $table) {
            if (! $desiredSchema->hasTable($this->unqualifiedTableName($table))) {
                continue;
            }

            $tables[] = clone $table;
        }

        $sequences = [];
        foreach ($currentSchema->getSequences() as $sequence) {
            if (! $desiredSchema->hasSequence($sequence->getName())) {
                continue;
            }

            $sequences[] = clone $sequence;
        }

        return new Schema($tables, $sequences, $schemaConfig, $currentSchema->getNamespaces());
    }

    private function unqualifiedTableName(Table $table): string
    {
        $name = $table->getName();
        $separatorPosition = strrpos($name, '.');
        if ($separatorPosition !== false) {
            $name = substr($name, $separatorPosition + 1);
        }

        return strtolower($name);
    }
}
