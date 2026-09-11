<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Validation;

use Ucp\Sdk\Enum\UcpProtocolVersion;
use Ucp\Sdk\Exception\ConfigurationException;

/**
 * Resolves where the generated schema set for a protocol version lives on disk.
 *
 * The bundle used to build this path inline with the version written into it, next
 * to a `version` config node that nothing connected it to. Pointing the config at a
 * different version therefore kept validating against the compiled-in schema set,
 * and because the older set is the more permissive of the two the test suite stayed
 * green. Resolving through one function is what makes the two impossible to drift:
 * a version whose schemas are not on disk fails here, at container build time,
 * rather than on the first request that happens to be validated.
 *
 * @internal
 */
final class SchemaDirectoryLocator
{
    public static function generated(?string $version = null): string
    {
        $version ??= UcpProtocolVersion::current()->value;
        $directory = self::schemaRoot() . '/generated/' . $version;

        if (! is_dir($directory)) {
            throw new ConfigurationException(sprintf(
                'No generated schema set for UCP protocol version "%s"; expected it at "%s". '
                . 'Either the version is not one this SDK release ships (it serves %s), '
                . 'or the schemas have not been generated -- see scripts/sync-ucp-schemas.sh.',
                $version,
                $directory,
                implode(', ', UcpProtocolVersion::supportedVersions()),
            ));
        }

        return $directory;
    }

    public static function schemaRoot(): string
    {
        return dirname(__DIR__, 3) . '/resources/schema';
    }
}
