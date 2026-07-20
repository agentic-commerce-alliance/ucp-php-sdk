<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

final class MonetaryAmount
{
    /**
     * ISO 4217 currencies whose minor unit is not the default two decimals.
     */
    private const ZERO_DECIMAL_CURRENCIES = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
    private const THREE_DECIMAL_CURRENCIES = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];
    private const FOUR_DECIMAL_CURRENCIES = ['CLF', 'UYW'];

    private function __construct(
        public readonly int $minorUnits,
        public readonly string $currency,
    ) {
    }

    public static function fromMajorUnits(float $amount, string $currency = 'EUR'): self
    {
        return new self((int) round($amount * 10 ** self::exponent($currency)), $currency);
    }

    public static function exponent(string $currency): int
    {
        $currency = strtoupper($currency);

        if (in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return 0;
        }

        if (in_array($currency, self::THREE_DECIMAL_CURRENCIES, true)) {
            return 3;
        }

        if (in_array($currency, self::FOUR_DECIMAL_CURRENCIES, true)) {
            return 4;
        }

        return 2;
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
