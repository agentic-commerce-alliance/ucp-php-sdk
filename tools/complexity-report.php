<?php

declare(strict_types=1);

/*
 * Ranks the functions that are hardest to read and change, and says what to do about each.
 *
 *   php tools/complexity-report.php                      report only
 *   php tools/complexity-report.php --enforce            fail on hotspots the baseline does not hold
 *   php tools/complexity-report.php --update-baseline    accept the current hotspots
 *
 * The gate is a ratchet. Hotspots that already exist are recorded in the baseline and do not
 * fail; a new one, or an existing one getting worse, does. Lowering the baseline after a
 * refactoring is what makes the improvement stick. See docs/qa-complexity.md.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/ComplexityReport.php';

use Ucp\Sdk\Tools\ComplexityReport;

$repoRoot = dirname(__DIR__);
$options = getopt('', ['enforce', 'update-baseline', 'baseline:', 'json:', 'markdown:', 'top:', 'max-cognitive:', 'max-parameters:', 'max-lines:']);

$option = static fn (string $name, string $default): string => is_string($options[$name] ?? null) ? $options[$name] : $default;

$limits = [
    'cognitive' => (int) $option('max-cognitive', '15'),
    'parameters' => (int) $option('max-parameters', '5'),
    'lines' => (int) $option('max-lines', '80'),
];
$top = (int) $option('top', '15');
$baselinePath = $repoRoot . '/' . $option('baseline', 'tools/complexity-baseline.json');
$jsonPath = $repoRoot . '/' . $option('json', 'var/reports/complexity/report.json');
$markdownPath = $repoRoot . '/' . $option('markdown', 'var/reports/complexity/report.md');

$report = (new ComplexityReport($repoRoot))->analyse();
$hotspots = hotspots($report, $limits);

if (isset($options['update-baseline'])) {
    writeFile($baselinePath, json_encode(baselineOf($hotspots, $report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    fwrite(STDOUT, sprintf("Baseline written to %s.\n", substr($baselinePath, strlen($repoRoot) + 1)));

    exit(0);
}

$baseline = is_file($baselinePath) ? json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR) : null;
$regressions = $baseline === null ? [] : regressions($hotspots, $report, $baseline);
$markdown = markdown($report, $hotspots, $limits, $top, $regressions);

writeFile($jsonPath, json_encode($report + ['limits' => $limits], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
writeFile($markdownPath, $markdown);
fwrite(STDOUT, $markdown);

if (!isset($options['enforce'])) {
    exit(0);
}

if ($baseline === null) {
    fwrite(STDERR, "No baseline at the given path. Run composer complexity:baseline first.\n");

    exit(1);
}

if ($regressions !== []) {
    fwrite(STDERR, "\nComplexity gate failed. Simplify the code above, or, if the increase is deliberate, run composer complexity:baseline and explain it in the pull request.\n");

    exit(1);
}

fwrite(STDOUT, "\nComplexity gate passed: no hotspot is new or worse than the baseline.\n");

/**
 * @param array{functions: list<array<string, mixed>>, clones: list<array{lines: int, tokens: int, occurrences: list<array{file: string, start: int, end: int}>}>, duplicated_lines: int} $report
 * @param array{cognitive: int, parameters: int, lines: int} $limits
 *
 * @return array{cognitive: list<array<string, mixed>>, parameters: list<array<string, mixed>>, lines: list<array<string, mixed>>}
 */
function hotspots(array $report, array $limits): array
{
    $over = static function (string $metric) use ($report, $limits): array {
        $rows = array_values(array_filter(
            $report['functions'],
            static fn (array $row): bool => $row[$metric] > $limits[$metric] && !($metric === 'parameters' && $row['data_constructor']),
        ));
        usort($rows, static fn (array $a, array $b): int => [$b[$metric], $a['symbol']] <=> [$a[$metric], $b['symbol']]);

        return $rows;
    };

    return ['cognitive' => $over('cognitive'), 'parameters' => $over('parameters'), 'lines' => $over('lines')];
}

/**
 * @param array{cognitive: list<array<string, mixed>>, parameters: list<array<string, mixed>>, lines: list<array<string, mixed>>} $hotspots
 * @param array{functions: list<array<string, mixed>>, clones: list<array{lines: int, tokens: int, occurrences: list<array{file: string, start: int, end: int}>}>, duplicated_lines: int} $report
 *
 * @return array<string, mixed>
 */
function baselineOf(array $hotspots, array $report): array
{
    $baseline = [];
    foreach ($hotspots as $metric => $rows) {
        $baseline[$metric] = [];
        foreach ($rows as $row) {
            $baseline[$metric][$row['symbol']] = $row[$metric];
        }
        ksort($baseline[$metric]);
    }
    $baseline['duplicated_lines'] = $report['duplicated_lines'];

    return $baseline;
}

/**
 * @param array{cognitive: list<array<string, mixed>>, parameters: list<array<string, mixed>>, lines: list<array<string, mixed>>} $hotspots
 * @param array{functions: list<array<string, mixed>>, clones: list<array{lines: int, tokens: int, occurrences: list<array{file: string, start: int, end: int}>}>, duplicated_lines: int} $report
 * @param array<string, mixed> $baseline
 *
 * @return list<string>
 */
function regressions(array $hotspots, array $report, array $baseline): array
{
    $found = [];
    foreach ($hotspots as $metric => $rows) {
        foreach ($rows as $row) {
            $allowed = $baseline[$metric][$row['symbol']] ?? null;
            if ($allowed === null) {
                $found[] = sprintf('new %s hotspot: `%s` is %d (%s:%d)', $metric, $row['symbol'], $row[$metric], $row['file'], $row['line']);
            } elseif ($row[$metric] > $allowed) {
                $found[] = sprintf('%s of `%s` rose from %d to %d (%s:%d)', $metric, $row['symbol'], $allowed, $row[$metric], $row['file'], $row['line']);
            }
        }
    }

    if ($report['duplicated_lines'] > ($baseline['duplicated_lines'] ?? 0)) {
        $found[] = sprintf('duplicated lines rose from %d to %d', $baseline['duplicated_lines'] ?? 0, $report['duplicated_lines']);
    }

    return $found;
}

/**
 * @param array<string, mixed> $row
 */
function hint(string $metric, array $row): string
{
    $free = $row['surface'] !== 'public-api';

    return match ($metric) {
        'cognitive' => 'Name the steps: extract nested branches into private methods and turn the outer conditions into guard clauses.',
        'parameters' => match (true) {
            str_ends_with($row['symbol'], '::__construct') && $free => 'Many collaborators usually means several responsibilities. Split the class, or group arguments that always travel together into one object.',
            str_ends_with($row['symbol'], '::__construct') => 'Published constructor: changing it breaks BC. Add a named constructor or parameter object next to it and deprecate later.',
            $free => 'Group the arguments that always travel together into a small value object, or move fixed ones to constructor dependencies.',
            default => 'Published signature: changing it breaks BC. Introduce the simpler signature next to it and deprecate this one.',
        },
        default => 'Long body: split it into named steps so each reads on one screen.',
    };
}

/**
 * @param array{functions: list<array<string, mixed>>, clones: list<array{lines: int, tokens: int, occurrences: list<array{file: string, start: int, end: int}>}>, duplicated_lines: int} $report
 * @param array{cognitive: list<array<string, mixed>>, parameters: list<array<string, mixed>>, lines: list<array<string, mixed>>} $hotspots
 * @param array{cognitive: int, parameters: int, lines: int} $limits
 * @param list<string>                                        $regressions
 */
function markdown(array $report, array $hotspots, array $limits, int $top, array $regressions): string
{
    $out = "## Complexity report\n\n";
    $out .= sprintf("Analysed %d functions. Cognitive complexity follows SonarSource's definition.\n\n", count($report['functions']));
    $out .= "| Check | Limit | Over the limit |\n|---|---|---|\n";
    foreach (['cognitive' => 'Cognitive complexity', 'parameters' => 'Parameters', 'lines' => 'Body lines'] as $metric => $label) {
        $out .= sprintf("| %s | %d | %d |\n", $label, $limits[$metric], count($hotspots[$metric]));
    }
    $out .= sprintf("| Duplicated lines | | %d in %d clones |\n\n", $report['duplicated_lines'], count($report['clones']));

    if ($regressions !== []) {
        $out .= "### Gate findings\n\n" . implode('', array_map(static fn (string $line): string => "- {$line}\n", $regressions)) . "\n";
    }

    $sections = [
        'cognitive' => ['Cognitive complexity', 'Simplifying a body never breaks backward compatibility, whatever the visibility.'],
        'parameters' => ['Parameter count', 'A signature can change freely when the surface is private or internal. A public-api signature is checked by the BC gate.'],
        'lines' => ['Body length', 'Simplifying a body never breaks backward compatibility, whatever the visibility.'],
    ];
    foreach ($sections as $metric => [$label, $note]) {
        if ($hotspots[$metric] === []) {
            continue;
        }

        $withSurface = $metric === 'parameters';
        $out .= sprintf("### %s, top %d\n\n%s\n\n", $label, $top, $note);
        $out .= $withSurface ? "| Value | Function | Surface | Hint |\n|---|---|---|---|\n" : "| Value | Function | Hint |\n|---|---|---|\n";
        foreach (array_slice($hotspots[$metric], 0, $top) as $row) {
            $function = sprintf('`%s`<br>%s:%d', $row['symbol'], $row['file'], $row['line']);
            $out .= $withSurface
                ? sprintf("| %d | %s | %s | %s |\n", $row[$metric], $function, $row['surface'], hint($metric, $row))
                : sprintf("| %d | %s | %s |\n", $row[$metric], $function, hint($metric, $row));
        }
        $out .= "\n";
    }

    if ($report['clones'] !== []) {
        $out .= sprintf("### Duplicated code, top %d\n\nExtract the shared part into a private helper or an internal service.\n\n| Lines | Occurrences |\n|---|---|\n", $top);
        foreach (array_slice($report['clones'], 0, $top) as $clone) {
            $where = array_map(static fn (array $o): string => sprintf('%s:%d-%d', $o['file'], $o['start'], $o['end']), $clone['occurrences']);
            $out .= sprintf("| %d | %s |\n", $clone['lines'], implode('<br>', $where));
        }
        $out .= "\n";
    }

    return $out;
}

function writeFile(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    file_put_contents($path, $contents);
}
