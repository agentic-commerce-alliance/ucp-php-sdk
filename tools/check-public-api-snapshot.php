<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require $root . '/tools/build-public-api-snapshot.php';

$current = file_get_contents($root . '/tools/public-api-snapshot.txt');
$expected = file_exists($root . '/tools/public-api-snapshot.expected.txt')
    ? file_get_contents($root . '/tools/public-api-snapshot.expected.txt')
    : '';

if ($current !== $expected) {
    fwrite(STDERR, "Public API snapshot drift detected. Re-run composer public-api:dump and review changes.\n");
    exit(1);
}

echo "Public API snapshot matches.\n";
