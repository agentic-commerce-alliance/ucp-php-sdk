<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

/**
 * Price per unit, and the two measures needed to display it honestly.
 *
 * `measure` is what the product contains — a 750 mL bottle. `reference` is the denominator the
 * price is shown against — per 100 mL. Keeping them apart is what lets a shelf label read
 * "€12.00 (€1.60 per 100 mL)" for a 750 mL bottle without the platform having to guess either
 * number.
 */
final class UnitPrice
{
    public function __construct(
        /** ISO 4217 minor units, as everywhere else on the wire. */
        public readonly int $amount,
        public readonly string $currency,
        public readonly Measure $measure,
        public readonly Measure $reference,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => strtoupper($this->currency),
            'measure' => $this->measure->toArray(),
            'reference' => $this->reference->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): ?self
    {
        $amount = $payload['amount'] ?? null;
        $currency = $payload['currency'] ?? null;
        $measure = is_array($payload['measure'] ?? null) ? Measure::fromArray($payload['measure']) : null;
        $reference = is_array($payload['reference'] ?? null) ? Measure::fromArray($payload['reference']) : null;

        // All four are required by the schema, so a partial unit price is dropped rather than
        // published half-formed -- a price with no denominator is not displayable.
        if (! is_int($amount) || ! is_string($currency) || $measure === null || $reference === null) {
            return null;
        }

        return new self($amount, $currency, $measure, $reference);
    }
}
