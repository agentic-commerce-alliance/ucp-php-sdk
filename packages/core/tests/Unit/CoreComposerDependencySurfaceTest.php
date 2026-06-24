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
        $composerJson = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('symfony/http-foundation', $composerJson['require']);
        self::assertArrayNotHasKey('symfony/http-client', $composerJson['require']);
        self::assertArrayNotHasKey('symfony/http-client-contracts', $composerJson['require']);
        self::assertArrayNotHasKey('symfony/event-dispatcher-contracts', $composerJson['require']);
    }
}
