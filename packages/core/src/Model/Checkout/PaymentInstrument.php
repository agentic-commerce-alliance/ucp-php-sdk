<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

final class PaymentInstrument
{
    /**
     * @param array<string, mixed> $credential
     */
    public function __construct(
        public readonly string $type,
        public readonly string $handlerId,
        public readonly array $credential = [],
    ) {
    }
}
