<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

final class BuyerConsent
{
    public function __construct(
        public readonly bool $granted,
        public readonly ?string $timestamp = null,
    ) {
    }
}
