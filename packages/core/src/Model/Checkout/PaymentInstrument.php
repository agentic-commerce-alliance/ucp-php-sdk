<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

final readonly class PaymentInstrument
{
    /**
     * @param array<string, mixed> $credential
     */
    public function __construct(
        public string $type,
        public string $handlerId,
        public array $credential = [],
    ) {
    }
}
