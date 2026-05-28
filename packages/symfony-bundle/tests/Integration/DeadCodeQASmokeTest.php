<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Tools\InternalClassReferenceScanner;

final class DeadCodeQASmokeTest extends TestCase
{
    #[Test]
    public function internalReferenceScanStaysAlignedWithCompiledSymfonyContainers(): void
    {
        $allowlist = require dirname(__DIR__, 4) . '/tools/internal-class-allowlist.php';

        $report = InternalClassReferenceScanner::createDefault(dirname(__DIR__, 4))->scan($allowlist);

        self::assertSame([], $report['unreferenced_classes'], json_encode($report['unreferenced_classes'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
