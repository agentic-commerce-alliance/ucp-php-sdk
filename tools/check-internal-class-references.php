<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/InternalClassReferenceScanner.php';

use Ucp\Sdk\Tools\InternalClassReferenceScanner;

$repoRoot = dirname(__DIR__);
$options = parseOptions($argv);
$allowlist = loadAllowlist($repoRoot . '/' . ($options['allowlist'] ?? 'tools/internal-class-allowlist.php'));
$reportPath = isset($options['report']) ? $repoRoot . '/' . $options['report'] : null;

$report = InternalClassReferenceScanner::createDefault($repoRoot)->scan($allowlist);

if ($reportPath !== null) {
    $directory = dirname($reportPath);
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
}

if ($report['unreferenced_classes'] === []) {
    fwrite(STDOUT, sprintf("Internal class reference scan passed. Checked %d concrete classes.\n", $report['checked_classes']));

    exit(0);
}

fwrite(STDERR, "Unreferenced internal or runtime classes were found:\n");
foreach ($report['unreferenced_classes'] as $className => $file) {
    fwrite(STDERR, sprintf("- %s (%s)\n", $className, $file));
}

exit(1);

/**
 * @return array<string, string>
 */
function parseOptions(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }

        [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '1');
        $options[$name] = $value;
    }

    return $options;
}

/**
 * @return array<string, array{reason: string, owner: string}>
 */
function loadAllowlist(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $data = require $path;
    if (!is_array($data)) {
        throw new RuntimeException('Internal class allowlist must return an array.');
    }

    $validated = [];
    foreach ($data as $className => $metadata) {
        if (!is_string($className) || $className === '') {
            throw new RuntimeException('Internal class allowlist keys must be non-empty class names.');
        }

        if (!is_array($metadata) || !isset($metadata['reason'], $metadata['owner'])) {
            throw new RuntimeException(sprintf('Allowlist entry for %s must define reason and owner.', $className));
        }

        $reason = trim((string) $metadata['reason']);
        $owner = trim((string) $metadata['owner']);
        if ($reason === '' || $owner === '') {
            throw new RuntimeException(sprintf('Allowlist entry for %s must use non-empty reason and owner values.', $className));
        }

        $validated[$className] = [
            'reason' => $reason,
            'owner' => $owner,
        ];
    }

    return $validated;
}
