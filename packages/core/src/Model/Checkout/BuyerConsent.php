<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

final readonly class BuyerConsent
{
    public function __construct(
        public bool $granted,
        public ?string $timestamp = null,
    ) {
    }
}
