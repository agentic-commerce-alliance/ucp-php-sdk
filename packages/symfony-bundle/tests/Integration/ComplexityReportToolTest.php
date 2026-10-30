<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Tools\ComplexityReport;

/**
 * The expected cognitive complexity values are SonarSource's own worked examples from the
 * white paper the metric is defined in, not numbers read back from the implementation.
 */
final class ComplexityReportToolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ucp-complexity-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/src/*.php') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root . '/src');
        rmdir($this->root);
    }

    #[Test]
    public function scoresTheWhitePaperNestingExampleAsNine(): void
    {
        $this->writeSource('Nesting.php', <<<'PHP'
            <?php
            namespace Ucp\Sdk\Internal;

            final class Nesting
            {
                public function myMethod(bool $condition1, bool $condition2): void
                {
                    try {
                        if ($condition1) {
                            for ($i = 0; $i < 10; $i++) {
                                while ($condition2) {
                                    $condition2 = false;
                                }
                            }
                        }
                    } catch (\RuntimeException|\LogicException $e) {
                        if ($condition2) {
                            return;
                        }
                    }
                }
            }
            PHP);

        self::assertSame(9, $this->metricsOf('Ucp\Sdk\Internal\Nesting::myMethod')['cognitive']);
    }

    #[Test]
    public function scoresASwitchAsOneAndEachSequenceOfLikeBooleanOperatorsAsOne(): void
    {
        $this->writeSource('Words.php', <<<'PHP'
            <?php
            namespace Ucp\Sdk\Internal;

            final class Words
            {
                public function getWords(int $number): string
                {
                    switch ($number) {
                        case 1:
                            return 'one';
                        case 2:
                            return 'a couple';
                        default:
                            return 'lots';
                    }
                }

                public function mixedOperators(bool $a, bool $b, bool $c, bool $d, bool $e, bool $f): bool
                {
                    if ($a && $b && $c || $d || $e && $f) {
                        return true;
                    }

                    return false;
                }
            }
            PHP);

        self::assertSame(1, $this->metricsOf('Ucp\Sdk\Internal\Words::getWords')['cognitive']);
        // +1 for the `if`, +3 for the sequences `&& &&`, `|| ||` and `&&`.
        self::assertSame(4, $this->metricsOf('Ucp\Sdk\Internal\Words::mixedOperators')['cognitive']);
    }

    #[Test]
    public function separatesSignaturesThatCanChangeFromPublishedOnes(): void
    {
        $this->writeSource('Published.php', <<<'PHP'
            <?php
            namespace Ucp\Sdk\Service;

            final class Published
            {
                public function open(int $a): void {}
                protected function sealed(int $a): void {}
                private function hidden(int $a): void {}
            }

            class Extensible
            {
                protected function hook(int $a): void {}
            }
            PHP);
        $this->writeSource('Helper.php', <<<'PHP'
            <?php
            namespace Ucp\Sdk\Internal\Service;

            class Helper
            {
                public function run(int $a): void {}
            }
            PHP);

        self::assertSame('public-api', $this->metricsOf('Ucp\Sdk\Service\Published::open')['surface']);
        self::assertSame('private', $this->metricsOf('Ucp\Sdk\Service\Published::sealed')['surface'], 'protected in a final class has no subclass to break');
        self::assertSame('private', $this->metricsOf('Ucp\Sdk\Service\Published::hidden')['surface']);
        self::assertSame('public-api', $this->metricsOf('Ucp\Sdk\Service\Extensible::hook')['surface']);
        self::assertSame('internal', $this->metricsOf('Ucp\Sdk\Internal\Service\Helper::run')['surface']);
    }

    #[Test]
    public function marksPublicPromotedConstructorsAsDataShapes(): void
    {
        $this->writeSource('Money.php', <<<'PHP'
            <?php
            namespace Ucp\Sdk\Model\Common;

            final class Money
            {
                public function __construct(
                    public readonly int $amount,
                    public readonly string $currency,
                ) {
                }
            }
            PHP);
        $this->writeSource('Service.php', <<<'PHP'
            <?php
            namespace Ucp\Sdk\Internal\Service;

            final class Service
            {
                public function __construct(
                    private readonly \Closure $a,
                    private readonly \Closure $b,
                ) {
                }
            }
            PHP);

        $this->writeSource('Window.php', <<<'PHP'
            <?php
            namespace Ucp\Sdk\Internal\Service;

            final class Window
            {
                public function __construct(
                    public readonly int $from,
                    public readonly int $to,
                ) {
                }
            }
            PHP);

        self::assertTrue($this->metricsOf('Ucp\Sdk\Model\Common\Money::__construct')['data_constructor']);
        self::assertSame(2, $this->metricsOf('Ucp\Sdk\Model\Common\Money::__construct')['parameters']);
        self::assertFalse($this->metricsOf('Ucp\Sdk\Internal\Service\Service::__construct')['data_constructor'], 'collaborators are not a data shape');
        self::assertTrue($this->metricsOf('Ucp\Sdk\Internal\Service\Window::__construct')['data_constructor'], 'the shape decides, not the namespace');
    }

    #[Test]
    public function reportsCodeCopiedToThreePlacesAsOneCloneIgnoringFormattingAndComments(): void
    {
        $body = $this->longBody();
        $this->writeSource('First.php', "<?php\nfinal class First\n{\n    public function run(array \$input): array\n    {\n{$body}\n    }\n}\n");
        $this->writeSource('Second.php', "<?php\nfinal class Second\n{\n    public function run(array \$input): array\n    {\n        // a comment the copy gained\n" . str_replace('    ', '  ', $body) . "\n    }\n}\n");
        $this->writeSource('Third.php', "<?php\nfinal class Third\n{\n    public function run(array \$input): array\n    {\n{$body}\n    }\n}\n");

        $report = $this->report();

        self::assertCount(1, $report['clones']);
        self::assertSame(['src/First.php', 'src/Second.php', 'src/Third.php'], array_column($report['clones'][0]['occurrences'], 'file'));
        self::assertSame(2 * $report['clones'][0]['lines'], $report['duplicated_lines']);
    }

    #[Test]
    public function doesNotReportRepetitionInsideOneBlockAsAClone(): void
    {
        $rows = str_repeat("        ['id' => 'x', 'value' => 1, 'enabled' => true],\n", 40);
        $this->writeSource('Table.php', "<?php\nfinal class Table\n{\n    public const ROWS = [\n{$rows}    ];\n}\n");

        self::assertSame([], $this->report()['clones']);
    }

    private function longBody(): string
    {
        $lines = [];
        foreach (['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta'] as $key) {
            $lines[] = sprintf("        \$%s = isset(\$input['%s']) ? (string) \$input['%s'] : '';", $key, $key, $key);
        }
        $lines[] = '        return [$alpha, $beta, $gamma, $delta, $epsilon, $zeta];';

        return implode("\n", $lines);
    }

    private function writeSource(string $name, string $code): void
    {
        file_put_contents($this->root . '/src/' . $name, $code);
    }

    /**
     * @return array{functions: list<array<string, mixed>>, clones: list<array{lines: int, tokens: int, occurrences: list<array{file: string, start: int, end: int}>}>, duplicated_lines: int}
     */
    private function report(): array
    {
        return (new ComplexityReport($this->root, ['src'], 40, 5))->analyse();
    }

    /**
     * @return array<string, mixed>
     */
    private function metricsOf(string $symbol): array
    {
        foreach ($this->report()['functions'] as $row) {
            if ($row['symbol'] === $symbol) {
                return $row;
            }
        }

        self::fail(sprintf('No metrics for %s.', $symbol));
    }
}
