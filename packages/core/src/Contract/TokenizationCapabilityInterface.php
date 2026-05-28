<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

interface TokenizationCapabilityInterface extends CapabilityInterface
{
    /**
     * @return array<string, mixed>
     */
    public function tokenize(PaymentInstrument $instrument, RequestContext $context): array;
}
