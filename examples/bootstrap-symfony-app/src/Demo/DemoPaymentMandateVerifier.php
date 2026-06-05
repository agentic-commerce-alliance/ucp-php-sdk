<?php

declare(strict_types=1);

namespace BootstrapSymfonyApp\Demo;

use Ucp\Sdk\Contract\PaymentMandateVerifierInterface;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

final class DemoPaymentMandateVerifier implements PaymentMandateVerifierInterface
{
    public function verify(PaymentInstrument $instrument, RequestContext $context): void
    {
    }
}
