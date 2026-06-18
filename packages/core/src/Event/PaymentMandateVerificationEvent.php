<?php

declare(strict_types=1);

namespace Ucp\Sdk\Event;

use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

final class PaymentMandateVerificationEvent
{
    public function __construct(
        private readonly PaymentInstrument $instrument,
        private readonly RequestContext $context,
    ) {
    }

    public function getInstrument(): PaymentInstrument
    {
        return $this->instrument;
    }

    public function getContext(): RequestContext
    {
        return $this->context;
    }
}
