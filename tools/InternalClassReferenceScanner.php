<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tools;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class InternalClassReferenceScanner
{
    /**
     * @param list<string> $candidateRoots
     * @param list<string> $referenceRoots
     */
    public function __construct(
        private readonly string $repoRoot,
        private readonly array $candidateRoots,
        private readonly array $referenceRoots,
        private readonly ?Parser $parser = null,
    ) {
    }

    public static function createDefault(string $repoRoot): self
    {
        return new self(
            $repoRoot,
            [
                $repoRoot . '/packages/core/src/Internal',
                $repoRoot . '/packages/symfony-bundle/src/Bridge',
                $repoRoot . '/packages/symfony-bundle/src/EventListener',
                $repoRoot . '/packages/symfony-bundle/src/Command',
                $repoRoot . '/packages/symfony-bundle/src/Controller',
            ],
            [
                $repoRoot . '/packages',
                $repoRoot . '/examples',
                $repoRoot . '/tools',
            ],
            (new ParserFactory())->createForNewestSupportedVersion(),
        );
    }

    /**
     * @param array<string, array{reason: string, owner: string}> $allowlist
     * @return array{
     *     checked_classes: int,
     *     allowlisted_classes: array<string, array{reason: string, owner: string}>,
     *     unreferenced_classes: array<string, string>
     * }
     */
    public function scan(array $allowlist = []): array
    {
        $candidateFiles = $this->collectPhpFiles($this->candidateRoots);
        $referenceFiles = $this->collectPhpFiles($this->referenceRoots);

        $classMap = [];
        foreach ($candidateFiles as $file) {
            foreach ($this->extractConcreteClasses($file) as $className) {
                $classMap[$className] = $file;
            }
        }

        $references = [];
        foreach ($referenceFiles as $file) {
            foreach ($this->extractReferences($file) as $name) {
                $references[$name] = true;
            }
        }

        $unreferenced = [];
        foreach ($classMap as $className => $file) {
            if (isset($allowlist[$className])) {
                continue;
            }

            if (!isset($references[$className])) {
                $unreferenced[$className] = substr($file, strlen($this->repoRoot) + 1);
            }
        }

        return [
            'checked_classes' => count($classMap),
            'allowlisted_classes' => $allowlist,
            'unreferenced_classes' => $unreferenced,
        ];
    }

    /**
     * @param list<string> $roots
     * @return list<string>
     */
    private function collectPhpFiles(array $roots): array
    {
        $files = [];

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof SplFileInfo) {
                    continue;
                }

                if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                    continue;
                }

                $path = $fileInfo->getRealPath();
                if ($path === false || str_contains($path, '/vendor/') || str_contains($path, '/var/')) {
                    continue;
                }

                $files[] = $path;
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /**
     * @return list<string>
     */
    private function extractConcreteClasses(string $file): array
    {
        $ast = $this->parseFile($file);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $collector = new class () extends NodeVisitorAbstract {
            /** @var list<string> */
            public array $classes = [];

            public function enterNode(Node $node): ?Node
            {
                if (!$node instanceof Node\Stmt\Class_ || $node->isAnonymous() || $node->isAbstract()) {
                    return null;
                }

                $name = $node->namespacedName?->toString();
                if (is_string($name) && $name !== '') {
                    $this->classes[] = ltrim($name, '\\');
                }

                return null;
            }
        };
        $traverser->addVisitor($collector);
        $traverser->traverse($ast);

        return $collector->classes;
    }

    /**
     * @return list<string>
     */
    private function extractReferences(string $file): array
    {
        $ast = $this->parseFile($file);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $collector = new class () extends NodeVisitorAbstract {
            /** @var array<string, true> */
            public array $references = [];

            public function enterNode(Node $node): ?Node
            {
                if ($node instanceof Node\Expr\New_) {
                    $this->collectName($node->class);

                    return null;
                }

                if ($node instanceof Node\Expr\Instanceof_) {
                    $this->collectName($node->class);

                    return null;
                }

                if ($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\ClassConstFetch || $node instanceof Node\Expr\StaticPropertyFetch) {
                    $this->collectName($node->class);

                    return null;
                }

                if ($node instanceof Node\Stmt\Class_) {
                    $this->collectName($node->extends);
                    foreach ($node->implements as $implement) {
                        $this->collectName($implement);
                    }

                    return null;
                }

                if ($node instanceof Node\Stmt\Interface_) {
                    foreach ($node->extends as $extend) {
                        $this->collectName($extend);
                    }

                    return null;
                }

                if ($node instanceof Node\Stmt\TraitUse) {
                    foreach ($node->traits as $trait) {
                        $this->collectName($trait);
                    }

                    return null;
                }

                if ($node instanceof Node\Stmt\Catch_) {
                    foreach ($node->types as $type) {
                        $this->collectName($type);
                    }

                    return null;
                }

                if ($node instanceof Node\Param) {
                    $this->collectType($node->type);

                    return null;
                }

                if ($node instanceof Node\FunctionLike) {
                    $this->collectType($node->getReturnType());

                    return null;
                }

                if ($node instanceof Node\Stmt\Property) {
                    $this->collectType($node->type);

                    return null;
                }

                return null;
            }

            private function collectType(Node|string|null $type): void
            {
                if ($type instanceof Node\NullableType) {
                    $this->collectType($type->type);

                    return;
                }

                if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
                    foreach ($type->types as $innerType) {
                        $this->collectType($innerType);
                    }

                    return;
                }

                if ($type instanceof Node\Name) {
                    $this->collectName($type);
                }
            }

            private function collectName(Node|string|null $name): void
            {
                if (!$name instanceof Node\Name) {
                    return;
                }

                $resolved = $name->getAttribute('resolvedName');
                $value = $resolved instanceof Node\Name ? $resolved->toString() : $name->toString();
                $value = ltrim($value, '\\');

                if ($value === '' || !str_contains($value, '\\')) {
                    return;
                }

                $this->references[$value] = true;
            }
        };
        $traverser->addVisitor($collector);
        $traverser->traverse($ast);

        return array_keys($collector->references);
    }

    /**
     * @return list<Node\Stmt>
     */
    private function parseFile(string $file): array
    {
        $code = file_get_contents($file);
        if ($code === false) {
            throw new RuntimeException(sprintf('Unable to read %s.', $file));
        }

        return $this->parser?->parse($code) ?? [];
    }
}
