<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SymfonyInternalApiBoundaryTest extends TestCase
{
    #[Test]
    public function implementationClassesAreMarkedInternal(): void
    {
        $missingInternalDocblocks = [];
        foreach ($this->implementationFiles() as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);

            if (! preg_match('/(?:final\s+)?(?:readonly\s+)?(?:class|interface|trait|enum)\s+[A-Za-z_][A-Za-z0-9_]*/', $source)) {
                continue;
            }

            if (! str_contains($source, '@internal')) {
                $missingInternalDocblocks[] = substr($file, strlen($this->projectRoot()) + 1);
            }
        }

        self::assertSame([], $missingInternalDocblocks, json_encode($missingInternalDocblocks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<string>
     */
    private function implementationFiles(): array
    {
        $files = [];
        foreach ([
            'packages/symfony-bundle/src/Bridge',
            'packages/symfony-bundle/src/Command',
            'packages/symfony-bundle/src/Controller',
            'packages/symfony-bundle/src/EventListener',
            'packages/symfony-bundle/src/Internal',
            'packages/symfony-bundle/src/Operation',
        ] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->projectRoot() . '/' . $directory));
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getPathname();
                if (str_ends_with($path, 'Bridge/EmbeddedPageRendererInterface.php')) {
                    continue;
                }

                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}
