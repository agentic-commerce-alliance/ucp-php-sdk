<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CoreComposerDependencySurfaceTest extends TestCase
{
    #[Test]
    public function itKeepsTheCorePackageFreeOfSymfonyFrameworkComponents(): void
    {
        $require = self::coreRequire();

        self::assertArrayNotHasKey('symfony/http-foundation', $require);
        self::assertArrayNotHasKey('symfony/http-client', $require);
        self::assertArrayNotHasKey('symfony/http-client-contracts', $require);
        self::assertArrayNotHasKey('symfony/event-dispatcher-contracts', $require);
    }

    /**
     * Core needs nothing but PHP and extensions, which is what lets a consumer install it into any
     * dependency graph without a resolver conflict. phpseclib was the last runtime package, and it
     * cost a red pipeline the moment its 4.0 renamed its root namespace (#130) -- so the property
     * is asserted rather than assumed.
     */
    #[Test]
    public function itDeclaresNoRuntimeDependencyBeyondPhpAndExtensions(): void
    {
        $packages = array_values(array_filter(
            array_keys(self::coreRequire()),
            static fn (string $name): bool => $name !== 'php' && !str_starts_with($name, 'ext-'),
        ));

        self::assertSame([], $packages, json_encode($packages, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, string>
     */
    private static function coreRequire(): array
    {
        $composerJson = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($composerJson);
        self::assertIsArray($composerJson['require']);

        /** @var array<string, string> $require */
        $require = $composerJson['require'];

        return $require;
    }
}
