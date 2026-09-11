<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

/**
 * A quantity expressed in a unit: `value` steps of `unit`, each step `10^-scale` of it.
 *
 * A 750 mL bottle is value 750 against a millilitre unit. Held as an integer because the spec
 * bounds it at ±(2^53−1) with a maximum scale of 15, which is the range a float stops being
 * able to represent exactly.
 */
final class Measure
{
    public function __construct(
        public readonly Unit $unit,
        public readonly int $value,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [...$this->unit->toArray(), 'value' => $this->value];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): ?self
    {
        $unit = Unit::fromArray($payload);
        $value = $payload['value'] ?? null;
        if ($unit === null || ! is_int($value)) {
            return null;
        }

        return new self($unit, $value);
    }
}
