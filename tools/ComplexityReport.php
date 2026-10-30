<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tools;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TomasVotruba\CognitiveComplexity\AstCognitiveComplexityAnalyzer;
use TomasVotruba\CognitiveComplexity\DataCollector\CognitiveComplexityDataCollector;
use TomasVotruba\CognitiveComplexity\NodeAnalyzer\ComplexityAffectingNodeFinder;
use TomasVotruba\CognitiveComplexity\NodeTraverser\ComplexityNodeTraverserFactory;
use TomasVotruba\CognitiveComplexity\NodeVisitor\ComplexityNodeVisitor;
use TomasVotruba\CognitiveComplexity\NodeVisitor\NestingNodeVisitor;

/**
 * Measures what makes code hard to read and change: cognitive complexity, parameter
 * count, body length and duplicated token runs, per function-like.
 *
 * Cognitive complexity is not computed here. It comes from tomasvotruba/cognitive-complexity,
 * an implementation of SonarSource's published definition, so the numbers mean what that
 * white paper says rather than what this repository decided they mean.
 *
 * Every function is also classified by how freely it can be changed. A private method, or
 * any method of an internal class, can be reshaped in any release. A public method of a
 * published class is checked by scripts/bc-check.sh, so the hint for it is different.
 *
 * @phpstan-type FunctionMetrics array{
 *     symbol: string,
 *     file: string,
 *     line: int,
 *     cognitive: int,
 *     parameters: int,
 *     lines: int,
 *     surface: 'private'|'internal'|'public-api',
 *     data_constructor: bool
 * }
 * @phpstan-type CloneOccurrence array{file: string, start: int, end: int}
 * @phpstan-type CodeClone array{lines: int, tokens: int, occurrences: list<CloneOccurrence>}
 * @phpstan-type Report array{functions: list<FunctionMetrics>, clones: list<CodeClone>, duplicated_lines: int}
 */
final class ComplexityReport
{
    public const DEFAULT_PATHS = [
        'packages/core/src',
        'packages/symfony-bundle/src',
        'examples/bootstrap-symfony-app/src',
        'examples/merchant-symfony-app/src',
    ];

    /**
     * Namespaces AGENTS.md lists as internal. Anything outside them is treated as published,
     * which is the same assumption the BC check makes.
     */
    private const INTERNAL_NAMESPACES = [
        'Ucp\\Sdk\\Internal\\',
        'Ucp\\Sdk\\Symfony\\Internal\\',
        'Ucp\\Sdk\\Symfony\\Bridge\\',
        'Ucp\\Sdk\\Symfony\\EventListener\\',
        'BootstrapSymfonyApp\\',
        'MerchantSymfonyApp\\',
    ];

    /**
     * A constructor that only promotes its arguments in one of these namespace segments
     * describes the shape of a value. Its parameter count is the schema's field count, so
     * it is reported but not ranked as a parameter-list smell.
     */
    private const DATA_NAMESPACE_SEGMENTS = ['\\Model\\', '\\Event\\', '\\Exception\\'];

    private readonly Parser $parser;

    private readonly AstCognitiveComplexityAnalyzer $cognitive;

    /**
     * @param list<string> $paths            directories relative to the repository root
     * @param int          $minCloneTokens   shortest duplicated token run that counts
     * @param int          $minCloneLines    shortest duplicated line span that counts
     */
    public function __construct(
        private readonly string $repoRoot,
        private readonly array $paths = self::DEFAULT_PATHS,
        private readonly int $minCloneTokens = 70,
        private readonly int $minCloneLines = 5,
    ) {
        $this->parser = (new ParserFactory())->createForHostVersion();

        $collector = new CognitiveComplexityDataCollector();
        $finder = new ComplexityAffectingNodeFinder();
        $this->cognitive = new AstCognitiveComplexityAnalyzer(
            new ComplexityNodeTraverserFactory(
                new NestingNodeVisitor($collector, $finder),
                new ComplexityNodeVisitor($collector, $finder),
            ),
            $collector,
        );
    }

    /**
     * @return Report
     */
    public function analyse(): array
    {
        $functions = [];
        $tokenStreams = [];

        foreach ($this->phpFiles() as $relativePath => $absolutePath) {
            $code = (string) file_get_contents($absolutePath);
            array_push($functions, ...$this->functionMetrics($relativePath, $code));
            $tokenStreams[$relativePath] = $this->significantTokens($code);
        }

        $clones = $this->findClones($tokenStreams);

        return [
            'functions' => $functions,
            'clones' => $clones,
            'duplicated_lines' => array_sum(array_map(
                static fn (array $clone): int => $clone['lines'] * (count($clone['occurrences']) - 1),
                $clones,
            )),
        ];
    }

    /**
     * @return array<string, string> relative path => absolute path, sorted for stable output
     */
    private function phpFiles(): array
    {
        $files = [];

        foreach ($this->paths as $path) {
            $directory = $this->repoRoot . '/' . $path;
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $files[$path . substr($file->getPathname(), strlen($directory))] = $file->getPathname();
                }
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @return list<FunctionMetrics>
     */
    private function functionMetrics(string $file, string $code): array
    {
        $ast = $this->parser->parse($code) ?? [];
        $traverser = new NodeTraverser(new NameResolver());
        $ast = $traverser->traverse($ast);

        $metrics = [];
        $finder = new NodeFinder();

        foreach ($finder->findInstanceOf($ast, ClassLike::class) as $class) {
            $className = $class->namespacedName?->toString() ?? sprintf('class@anonymous(%s:%d)', $file, $class->getStartLine());

            foreach ($class->getMethods() as $method) {
                $metrics[] = $this->measure(
                    $className . '::' . $method->name->toString(),
                    $file,
                    $method,
                    $this->surface($className, $class, $method),
                    $this->isDataConstructor($className, $method),
                );
            }
        }

        foreach ($finder->findInstanceOf($ast, Function_::class) as $function) {
            $name = $function->namespacedName?->toString() ?? $function->name->toString();
            $metrics[] = $this->measure($name, $file, $function, $this->isInternal($name) ? 'internal' : 'public-api', false);
        }

        return $metrics;
    }

    /**
     * @param 'private'|'internal'|'public-api' $surface
     *
     * @return FunctionMetrics
     */
    private function measure(string $symbol, string $file, ClassMethod|Function_ $function, string $surface, bool $dataConstructor): array
    {
        return [
            'symbol' => $symbol,
            'file' => $file,
            'line' => $function->getStartLine(),
            'cognitive' => $function->stmts === null ? 0 : $this->cognitive->analyzeFunctionLike($function),
            'parameters' => count($function->params),
            'lines' => $function->getEndLine() - $function->getStartLine() + 1,
            'surface' => $surface,
            'data_constructor' => $dataConstructor,
        ];
    }

    /**
     * @return 'private'|'internal'|'public-api'
     */
    private function surface(string $className, ClassLike $class, ClassMethod $method): string
    {
        $finalClass = $class instanceof Class_ && ($class->isFinal() || $class->name === null);

        if ($method->isPrivate() || ($method->isProtected() && $finalClass)) {
            return 'private';
        }

        if ($this->isInternal($className) || str_contains((string) $class->getDocComment()?->getText(), '@internal')) {
            return 'internal';
        }

        return 'public-api';
    }

    private function isInternal(string $symbol): bool
    {
        foreach (self::INTERNAL_NAMESPACES as $namespace) {
            if (str_starts_with($symbol, $namespace)) {
                return true;
            }
        }

        return str_starts_with($symbol, 'class@anonymous');
    }

    private function isDataConstructor(string $className, ClassMethod $method): bool
    {
        if ($method->name->toLowerString() !== '__construct' || ($method->stmts ?? []) !== []) {
            return false;
        }

        foreach ($method->params as $param) {
            if ($param->flags === 0) {
                return false;
            }
        }

        foreach (self::DATA_NAMESPACE_SEGMENTS as $segment) {
            if (str_contains('\\' . $className, $segment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tokens that carry meaning, with their line. Whitespace and comments are dropped, so a
     * clone that differs only in formatting or documentation is still a clone.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function significantTokens(string $code): array
    {
        $tokens = [];

        foreach (\PhpToken::tokenize($code) as $token) {
            if ($token->isIgnorable()) {
                continue;
            }

            $tokens[] = [$token->text, $token->line];
        }

        return $tokens;
    }

    /**
     * Exact token-run clone detection, the approach phpcpd takes: hash every window of
     * minCloneTokens tokens, and extend a match while consecutive windows keep matching
     * consecutive windows of the first occurrence.
     *
     * @param array<string, list<array{0: string, 1: int}>> $tokenStreams
     *
     * @return list<CodeClone>
     */
    private function findClones(array $tokenStreams): array
    {
        /** @var array<string, array{0: string, 1: int}> $seen hash => [file, token index] */
        $seen = [];
        /** @var array<string, CodeClone> $clones */
        $clones = [];

        foreach ($tokenStreams as $file => $tokens) {
            $windowCount = count($tokens) - $this->minCloneTokens + 1;
            $runStart = null;
            $origin = null;

            // One step past the last window, so a run reaching the end of the file is recorded.
            for ($index = 0; $index <= $windowCount; ++$index) {
                $hash = null;
                $match = null;
                if ($index < $windowCount) {
                    $hash = md5(implode("\0", array_column(array_slice($tokens, $index, $this->minCloneTokens), 0)));
                    $match = $seen[$hash] ?? null;
                }

                if ($runStart !== null && $origin !== null) {
                    if ($match === [$origin[0], $origin[1] + ($index - $runStart)]) {
                        continue;
                    }

                    $this->recordClone($clones, $tokenStreams, $origin, [$file, $runStart], $index - 1 - $runStart + $this->minCloneTokens);
                    $runStart = null;
                    $origin = null;
                }

                // A window matching an overlapping window of the same file is repetition
                // inside one block, such as a long array literal, not a copy of it.
                if ($match !== null && !($match[0] === $file && $match[1] + $this->minCloneTokens > $index)) {
                    $runStart = $index;
                    $origin = $match;

                    continue;
                }

                if ($hash !== null) {
                    $seen[$hash] ??= [$file, $index];
                }
            }
        }

        $clones = array_values($clones);
        usort($clones, static fn (array $a, array $b): int => [$b['lines'], $b['tokens']] <=> [$a['lines'], $a['tokens']]);

        return $clones;
    }

    /**
     * @param array<string, CodeClone>                       $clones
     * @param array<string, list<array{0: string, 1: int}>> $tokenStreams
     * @param array{0: string, 1: int}                       $origin
     * @param array{0: string, 1: int}                       $copy
     */
    private function recordClone(array &$clones, array $tokenStreams, array $origin, array $copy, int $length): void
    {
        $first = self::span($tokenStreams, $origin, $length);
        $second = self::span($tokenStreams, $copy, $length);
        $lines = min($first['end'] - $first['start'], $second['end'] - $second['start']) + 1;

        if ($lines < $this->minCloneLines) {
            return;
        }

        // Grouped by where the code first occurs, so code copied to three places is one
        // clone with three occurrences rather than two overlapping pairs.
        $key = sprintf('%s:%d', $origin[0], $first['start']);
        $clones[$key] ??= ['lines' => $lines, 'tokens' => $length, 'occurrences' => [$first]];
        $clones[$key]['lines'] = min($clones[$key]['lines'], $lines);
        $clones[$key]['tokens'] = min($clones[$key]['tokens'], $length);
        $clones[$key]['occurrences'][] = $second;
    }

    /**
     * @param array<string, list<array{0: string, 1: int}>> $tokenStreams
     * @param array{0: string, 1: int}                       $at
     *
     * @return array{file: string, start: int, end: int}
     */
    private static function span(array $tokenStreams, array $at, int $length): array
    {
        $tokens = $tokenStreams[$at[0]];

        return ['file' => $at[0], 'start' => $tokens[$at[1]][1], 'end' => $tokens[$at[1] + $length - 1][1]];
    }
}
