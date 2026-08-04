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
    $declaringClass = $method->getDeclaringClass()->getName();

    $parameters = array_map(
        static fn (ReflectionParameter $parameter): string => buildParameterSignature($parameter, $declaringClass),
        $method->getParameters(),
    );

    $signature = $method->getName() . '(' . implode(', ', $parameters) . ')';

    if ($method->isStatic()) {
        $signature = 'static ' . $signature;
    }

    if ($method->hasReturnType()) {
        $signature .= ': ' . buildTypeSignature($method->getReturnType(), $declaringClass);
    }

    return $signature;
}

function buildParameterSignature(ReflectionParameter $parameter, string $declaringClass): string
{
    $chunks = [];

    if ($parameter->hasType()) {
        $chunks[] = buildTypeSignature($parameter->getType(), $declaringClass);
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

function buildTypeSignature(ReflectionType $type, string $declaringClass): string
{
    if ($type instanceof ReflectionNamedType) {
        // PHP 8.5 resolves a `self` type to the declaring class, while 8.2-8.4 report the
        // literal "self" — so reflection alone cannot tell `: self` from `: TheClass` on 8.5.
        // Collapse both spellings to "self" so the snapshot is identical on every supported
        // version. `static` is deliberately left alone: it reflects as "static" everywhere
        // and means something different from `self`.
        $name = $type->getName() === $declaringClass ? 'self' : $type->getName();

        return ($type->allowsNull() && $name !== 'mixed' ? '?' : '') . $name;
    }

    $nested = static fn (ReflectionType $nestedType): string => buildTypeSignature($nestedType, $declaringClass);

    if ($type instanceof ReflectionUnionType) {
        return implode('|', array_map($nested, $type->getTypes()));
    }

    if ($type instanceof ReflectionIntersectionType) {
        return implode('&', array_map($nested, $type->getTypes()));
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
