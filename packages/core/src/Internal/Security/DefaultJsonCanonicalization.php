<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

use Ucp\Sdk\Service\DeterministicJsonInterface;

/** @internal */
final class DefaultJsonCanonicalization implements DeterministicJsonInterface
{
    public function canonicalize(array $payload): string
    {
        return $this->canonicalizeValue($payload);
    }

    private function canonicalizeValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return $this->canonicalizeFloat($value);
        }

        if (is_string($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (! is_array($value)) {
            throw new \RuntimeException('Unsupported value for JSON canonicalization.');
        }

        if (array_is_list($value)) {
            return '[' . implode(',', array_map($this->canonicalizeValue(...), $value)) . ']';
        }

        $keys = array_keys($value);
        usort($keys, $this->compareObjectKeys(...));

        $pairs = [];
        foreach ($keys as $key) {
            $pairs[] = json_encode((string) $key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ':' . $this->canonicalizeValue($value[$key]);
        }

        return '{' . implode(',', $pairs) . '}';
    }

    private function canonicalizeFloat(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            throw new \RuntimeException('NaN and Infinity are not allowed in canonical JSON.');
        }

        if ($value === 0.0) {
            return '0';
        }

        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $encoded = preg_replace('/\\.0e/i', 'e', $encoded) ?? $encoded;

        return $encoded === '-0' ? '0' : $encoded;
    }

    private function compareObjectKeys(string $left, string $right): int
    {
        $leftUnits = $this->utf16CodeUnits($left);
        $rightUnits = $this->utf16CodeUnits($right);
        $limit = min(count($leftUnits), count($rightUnits));

        for ($index = 0; $index < $limit; ++$index) {
            if ($leftUnits[$index] === $rightUnits[$index]) {
                continue;
            }

            return $leftUnits[$index] <=> $rightUnits[$index];
        }

        return count($leftUnits) <=> count($rightUnits);
    }

    /**
     * @return list<int>
     */
    private function utf16CodeUnits(string $value): array
    {
        if (function_exists('mb_convert_encoding')) {
            $utf16 = mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
        } elseif (function_exists('iconv')) {
            $utf16 = iconv('UTF-8', 'UTF-16BE', $value);
        } else {
            throw new \RuntimeException('UTF-16 conversion support is required for JSON canonicalization.');
        }

        if (! is_string($utf16)) {
            throw new \RuntimeException('Unable to canonicalize JSON object keys.');
        }

        $units = unpack('n*', $utf16);

        return $units === false ? [] : array_values($units);
    }
}
