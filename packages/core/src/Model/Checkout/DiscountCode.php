<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

final readonly class DiscountCode
{
    public function __construct(
        public string $code,
    ) {
    }
}
