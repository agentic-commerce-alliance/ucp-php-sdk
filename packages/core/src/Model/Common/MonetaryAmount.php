<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

final class MonetaryAmount
{
    /**
     * ISO 4217 currencies whose minor unit is not the default two decimals.
     *
     * Sourced from the SIX Group ISO 4217 maintenance agency table
     * (https://www.six-group.com/en/products-services/financial-information/data-standards.html),
     * "List One" published 2024-01-01. Verified 2026-07-22.
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
        $currency = strtoupper($currency);

        return new self((int) round($amount * 10 ** self::exponent($currency)), $currency);
    }

    /**
     * Number of minor-unit decimals for the given currency.
     *
     * The currency code is matched case-insensitively. Unknown codes and ISO
     * 4217 entries whose minor unit is listed as "N.A." (e.g. XAU, XDR) fall
     * back to 2. This is the SDK's chosen default, not a value defined by ISO
     * 4217.
     */
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
