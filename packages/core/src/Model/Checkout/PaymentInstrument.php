<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

final class PaymentInstrument
{
    /**
     * @param array<string, mixed> $credential
     * @param array<string, mixed> $billingAddress a `postal_address`, as `types/payment_instrument.json` defines it
     */
    public function __construct(
        public readonly string $type,
        public readonly string $handlerId,
        public readonly array $credential = [],
        public readonly array $billingAddress = [],
    ) {
    }
}
