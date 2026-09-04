<?php

declare(strict_types=1);

$cloverPath = $argv[1] ?? 'var/reports/coverage/clover.xml';
$reportPath = $argv[2] ?? 'var/reports/coverage/internal-summary.json';
$enforceTargets = in_array('--enforce', $argv, true);
$repoRoot = dirname(__DIR__);

if (!is_file($cloverPath)) {
    fwrite(STDERR, sprintf("Coverage file not found: %s\n", $cloverPath));

    exit(1);
}

$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, sprintf("Unable to parse Clover report: %s\n", $cloverPath));

    exit(1);
}

$groups = [
    'core_internal' => [
        'label' => 'packages/core/src/Internal',
        'target' => 80.0,
        'include' => ['packages/core/src/Internal/'],
        'exclude' => [],
    ],
    // The published surface -- models, adapters, events, enums -- was measured by nothing
    // until now, and six AdapterBacked* wrappers had sat at zero coverage as a result.
    // Internal/ is where the algorithms live and rightly carries the higher bar; this is
    // the part adopters actually construct, and "never executed once" is not a state it
    // should be able to reach quietly.
    'core_public' => [
        'label' => 'packages/core/src public surface',
        'target' => 90.0,
        'include' => ['packages/core/src/'],
        'exclude' => ['packages/core/src/Internal/'],
    ],
    'symfony_runtime' => [
        'label' => 'packages/symfony-bundle/src runtime',
        'target' => 75.0,
        'include' => ['packages/symfony-bundle/src/'],
        'exclude' => [
            'packages/symfony-bundle/src/DependencyInjection/',
            'packages/symfony-bundle/src/UcpSdkBundle.php',
            'packages/symfony-bundle/src/UcpSdkConfiguration.php',
        ],
    ],
    'merchant_example' => [
        'label' => 'examples/merchant-symfony-app/src',
        'target' => 60.0,
        'include' => ['examples/merchant-symfony-app/src/'],
        'exclude' => [],
    ],
    'bootstrap_example' => [
        'label' => 'examples/bootstrap-symfony-app/src',
        'target' => null,
        'include' => ['examples/bootstrap-symfony-app/src/'],
        'exclude' => [],
    ],
];

$summary = [];
foreach ($groups as $groupName => $group) {
    $summary[$groupName] = [
        'label' => $group['label'],
        'target' => $group['target'],
        'covered_statements' => 0,
        'total_statements' => 0,
        'coverage' => 0.0,
    ];
}

$files = $xml->xpath('//file');
if (is_array($files)) {
    foreach ($files as $fileNode) {
        $name = (string) ($fileNode['name'] ?? '');
        if ($name === '') {
            continue;
        }

        $relativePath = str_replace('\\', '/', ltrim(str_replace($repoRoot, '', $name), '/'));
        $metricsNode = $fileNode->metrics[0] ?? null;
        if ($metricsNode === null) {
            continue;
        }

        $statements = (int) ($metricsNode['statements'] ?? 0);
        $coveredStatements = (int) ($metricsNode['coveredstatements'] ?? 0);

        foreach ($groups as $groupName => $group) {
            if (!matchesGroup($relativePath, $group['include'], $group['exclude'])) {
                continue;
            }

            $summary[$groupName]['total_statements'] += $statements;
            $summary[$groupName]['covered_statements'] += $coveredStatements;
        }
    }
}

foreach ($summary as $groupName => $group) {
    $coverage = $group['total_statements'] === 0
        ? 0.0
        : round(($group['covered_statements'] / $group['total_statements']) * 100, 2);

    $summary[$groupName]['coverage'] = $coverage;
}

$reportDirectory = dirname($reportPath);
if (!is_dir($reportDirectory)) {
    mkdir($reportDirectory, 0777, true);
}

file_put_contents($reportPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);

fwrite(STDOUT, "Internal coverage summary\n");
foreach ($summary as $group) {
    $target = $group['target'] !== null ? sprintf(' target %.2f%%', $group['target']) : ' report only';
    fwrite(
        STDOUT,
        sprintf(
            "- %s: %d/%d statements (%.2f%%)%s\n",
            $group['label'],
            $group['covered_statements'],
            $group['total_statements'],
            $group['coverage'],
            $target,
        ),
    );
}

if ($enforceTargets) {
    $failedGroups = [];

    foreach ($summary as $group) {
        if ($group['target'] === null) {
            continue;
        }

        if ($group['coverage'] < $group['target']) {
            $failedGroups[] = sprintf(
                '%s is below target: %.2f%% < %.2f%%',
                $group['label'],
                $group['coverage'],
                $group['target'],
            );
        }
    }

    if ($failedGroups !== []) {
        fwrite(STDERR, "Internal coverage gate failed.\n");
        foreach ($failedGroups as $failedGroup) {
            fwrite(STDERR, '- ' . $failedGroup . PHP_EOL);
        }

        exit(1);
    }
}

/**
 * @param list<string> $include
 * @param list<string> $exclude
 */
function matchesGroup(string $relativePath, array $include, array $exclude): bool
{
    foreach ($exclude as $excludedPrefix) {
        if (str_starts_with($relativePath, $excludedPrefix)) {
            return false;
        }
    }

    foreach ($include as $includedPrefix) {
        if (str_starts_with($relativePath, $includedPrefix)) {
            return true;
        }
    }

    return false;
}
