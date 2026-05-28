<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Adapter\Model\PaymentPreparation;
use Ucp\Sdk\Adapter\Model\TokenizationResult;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

interface PaymentAdapterInterface
{
    public function prepareInstrument(PaymentInstrument $instrument, RequestContext $context): PaymentPreparation;

    public function supportsTokenization(): bool;

    public function tokenize(PaymentInstrument $instrument, RequestContext $context): ?TokenizationResult;
}
