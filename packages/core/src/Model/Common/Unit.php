<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

/**
 * A unit of measure, and the granularity one step of it represents.
 *
 * This is what makes an integer quantity able to express a fractional purchase. UCP does not
 * allow a fractional `quantity`: it is "always an integer step count", and the unit a step is
 * denominated in comes from here. Half a kilo of coffee is 500 steps of a gram, or 5 steps of
 * a hectogram — not a quantity of 0.5.
 *
 * `scale` is that granularity: one step equals `10^-scale` of `unit`.
 */
final class Unit
{
    public function __construct(
        /** Stable machine identifier; the UN/CEFACT Rec20 code where one exists. */
        public readonly string $unit,
        /**
         * The label a platform must display. Required by the spec rather than derived, because
         * only the business knows how it sells the thing.
         */
        public readonly string $displayText,
        public readonly int $scale = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'unit' => $this->unit,
            'display_text' => $this->displayText,
        ];

        // Omitted at the default, which the schema defines as 0.
        if ($this->scale !== 0) {
            $payload['scale'] = $this->scale;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): ?self
    {
        $unit = $payload['unit'] ?? null;
        $displayText = $payload['display_text'] ?? null;
        if (! is_string($unit) || $unit === '' || ! is_string($displayText) || $displayText === '') {
            return null;
        }

        $scale = $payload['scale'] ?? 0;

        return new self($unit, $displayText, is_int($scale) ? $scale : 0);
    }
}
