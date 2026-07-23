<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

final class PaymentSelection
{
    /**
     * @param list<PaymentInstrument> $instruments
     */
    public function __construct(
        public readonly array $instruments,
    ) {
    }
}
