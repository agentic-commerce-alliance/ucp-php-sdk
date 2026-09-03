<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ucp\Sdk\Enum\UcpProtocolVersion;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Internal\Validation\GeneratedSchemaValidator;
use Ucp\Sdk\Internal\Validation\SchemaDirectoryLocator;
use Ucp\Sdk\Symfony\DependencyInjection\Configuration;
use Ucp\Sdk\Symfony\DependencyInjection\UcpSdkExtension;

/**
 * The protocol version has to come from one place.
 *
 * It used to come from three that did not know about each other: the `version`
 * config node, a schema directory with the version written into its path, and the
 * enum case the response envelope named directly. Setting `ucp_sdk.version` moved
 * the first and left the other two, so the SDK advertised one version, validated
 * against another's schemas and stamped a third into every response -- and the
 * suite stayed green, because each site was self-consistent and the older schema
 * set is the more permissive of the two.
 *
 * These tests fail if the sites drift apart again, which is what makes a version
 * bump a single edit rather than a search.
 */
final class ProtocolVersionSingleSourceTest extends TestCase
{
    #[Test]
    public function theConfigurationDefaultIsTheVersionTheSdkServes(): void
    {
        self::assertSame(UcpProtocolVersion::current()->value, $this->process([])['version']);
    }

    #[Test]
    public function theWiredSchemaDirectoryFollowsTheConfiguredVersion(): void
    {
        self::assertSame(
            SchemaDirectoryLocator::generated(UcpProtocolVersion::current()->value),
            $this->wiredSchemaDirectory([]),
        );
    }

    #[Test]
    public function theWiredSchemaDirectoryNamesTheConfiguredVersionOnDisk(): void
    {
        $version = $this->process([])['version'];

        self::assertStringEndsWith('/generated/' . $version, $this->wiredSchemaDirectory([]));
    }

    /**
     * Callers that just want "the schemas for the version we serve" -- the tests, and
     * adopters resolving the directory for their own validator -- say so by omitting
     * the argument rather than repeating the version.
     */
    #[Test]
    public function theLocatorDefaultsToTheVersionTheSdkServes(): void
    {
        self::assertSame(
            SchemaDirectoryLocator::generated(UcpProtocolVersion::current()->value),
            SchemaDirectoryLocator::generated(),
        );
    }

    #[Test]
    public function aVersionTheSdkCannotServeIsRejectedAtConfigurationTime(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unsupported UCP protocol version');

        $this->process(['version' => '1999-01-01']);
    }

    /**
     * A version the enum knows but whose schemas are not on disk must fail while the
     * container is being built. Deferring it to the first validated request turns a
     * deployment mistake into an intermittent runtime error on a live endpoint.
     */
    #[Test]
    public function aVersionWithNoGeneratedSchemasFailsWhileTheContainerIsBuilt(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('No generated schema set for UCP protocol version "2026-01-11"');

        SchemaDirectoryLocator::generated('2026-01-11');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function wiredSchemaDirectory(array $config): string
    {
        $container = new ContainerBuilder();
        (new UcpSdkExtension())->load([$config], $container);

        $argument = $container->getDefinition(GeneratedSchemaValidator::class)->getArgument(0);
        self::assertIsString($argument);

        return $argument;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
