<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

final readonly class MonetaryAmount
{
    private function __construct(
        public int $minorUnits,
        public string $currency,
    ) {
    }

    public static function fromMajorUnits(float $amount, string $currency = 'EUR'): self
    {
        return new self((int) round($amount * 100), $currency);
    }

    /**
     * @return array{amount: int, currency: string}
     */
    public function toPriceArray(): array
    {
        return [
            'amount' => $this->minorUnits,
            'currency' => $this->currency,
        ];
    }
}
