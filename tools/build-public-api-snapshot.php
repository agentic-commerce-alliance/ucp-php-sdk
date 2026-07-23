<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (! file_exists($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found. Run composer install first.\n");
    exit(1);
}

require $autoload;

$directories = [
    $root . '/packages/core/src' => 'Ucp\\Sdk\\',
];
$namespaces = [
    'Ucp\\Sdk\\Adapter\\',
    'Ucp\\Sdk\\Capability\\',
    'Ucp\\Sdk\\Contract\\',
    'Ucp\\Sdk\\Model\\',
    'Ucp\\Sdk\\Enum\\',
    'Ucp\\Sdk\\Exception\\',
    'Ucp\\Sdk\\Event\\',
    'Ucp\\Sdk\\Repository\\',
    'Ucp\\Sdk\\Service\\',
];

$classes = [];

foreach ($directories as $directory => $prefix) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace($directory . '/', '', $file->getPathname());
        $class = $prefix . str_replace(['/', '.php'], ['\\', ''], $relative);

        foreach ($namespaces as $namespace) {
            if (str_starts_with($class, $namespace) && (class_exists($class) || interface_exists($class) || enum_exists($class))) {
                $classes[] = $class;
                break;
            }
        }
    }
}

sort($classes);
$classes = array_values(array_unique($classes));

$lines = [];

foreach ($classes as $class) {
    $reflection = new ReflectionClass($class);
    $lines[] = sprintf('%s %s', $reflection->isInterface() ? 'interface' : ($reflection->isEnum() ? 'enum' : 'class'), $reflection->getName());

    $methods = array_filter(
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $reflection->getName(),
    );

    usort(
        $methods,
        static fn (ReflectionMethod $left, ReflectionMethod $right): int => $left->getName() <=> $right->getName(),
    );

    foreach ($methods as $method) {
        $lines[] = '  - ' . buildMethodSignature($method);
    }
}

file_put_contents($root . '/tools/public-api-snapshot.txt', implode(PHP_EOL, $lines) . PHP_EOL);

function buildMethodSignature(ReflectionMethod $method): string
{
    $parameters = array_map(
        static fn (ReflectionParameter $parameter): string => buildParameterSignature($parameter),
        $method->getParameters(),
    );

    $signature = $method->getName() . '(' . implode(', ', $parameters) . ')';

    if ($method->isStatic()) {
        $signature = 'static ' . $signature;
    }

    if ($method->hasReturnType()) {
        $signature .= ': ' . buildTypeSignature($method->getReturnType());
    }

    return $signature;
}

function buildParameterSignature(ReflectionParameter $parameter): string
{
    $chunks = [];

    if ($parameter->hasType()) {
        $chunks[] = buildTypeSignature($parameter->getType());
    }

    if ($parameter->isPassedByReference()) {
        $chunks[] = '&';
    }

    if ($parameter->isVariadic()) {
        $chunks[] = '...';
    }

    $chunks[] = '$' . $parameter->getName();

    if ($parameter->isDefaultValueAvailable() && ! $parameter->isVariadic()) {
        $chunks[] = '= ' . formatDefaultValue($parameter);
    }

    return implode(' ', array_filter($chunks, static fn (string $chunk): bool => $chunk !== ''));
}

function buildTypeSignature(ReflectionType $type): string
{
    if ($type instanceof ReflectionNamedType) {
        return ($type->allowsNull() && $type->getName() !== 'mixed' ? '?' : '') . $type->getName();
    }

    if ($type instanceof ReflectionUnionType) {
        return implode('|', array_map(buildTypeSignature(...), $type->getTypes()));
    }

    if ($type instanceof ReflectionIntersectionType) {
        return implode('&', array_map(buildTypeSignature(...), $type->getTypes()));
    }

    return 'mixed';
}

function formatDefaultValue(ReflectionParameter $parameter): string
{
    if (! $parameter->isDefaultValueAvailable()) {
        return 'null';
    }

    if ($parameter->isDefaultValueConstant()) {
        return (string) $parameter->getDefaultValueConstantName();
    }

    $value = $parameter->getDefaultValue();

    return match (true) {
        is_string($value) => "'" . str_replace("'", "\\'", $value) . "'",
        is_bool($value) => $value ? 'true' : 'false',
        $value === null => 'null',
        is_array($value) => '[]',
        default => var_export($value, true),
    };
}
