<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

interface PaymentMandateVerifierInterface
{
    public function verify(PaymentInstrument $instrument, RequestContext $context): void;
}
