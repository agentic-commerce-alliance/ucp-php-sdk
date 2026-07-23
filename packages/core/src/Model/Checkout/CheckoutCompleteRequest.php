<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

use Ucp\Sdk\Model\Ap2\Ap2CheckoutData;

final class CheckoutCompleteRequest
{
    public function __construct(
        public readonly string $id,
        public readonly ?PaymentSelection $payment = null,
        public readonly ?Ap2CheckoutData $ap2 = null,
    ) {
    }
}
