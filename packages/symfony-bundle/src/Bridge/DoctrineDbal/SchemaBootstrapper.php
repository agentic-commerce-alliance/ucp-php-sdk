<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DoctrineDbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaConfig;
use Doctrine\DBAL\Schema\Table;

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
        $currentSchema = $schemaManager->introspectSchema();
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
