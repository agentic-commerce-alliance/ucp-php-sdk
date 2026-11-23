<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Checkout;

use Ucp\Sdk\Model\Checkout\PaymentInstrument;

/**
 * Picks the instrument a request means out of the list it carries.
 *
 * The fallback instrument keeps `selected: false`, so `false` on the result does not mean "nothing chosen".
 *
 * @internal
 */
final class PaymentInstrumentSelector
{
    /**
     * @param list<PaymentInstrument> $instruments
     */
    public static function select(array $instruments): ?PaymentInstrument
    {
        foreach ($instruments as $instrument) {
            if ($instrument->selected) {
                return $instrument;
            }
        }

        return $instruments[0] ?? null;
    }
}
