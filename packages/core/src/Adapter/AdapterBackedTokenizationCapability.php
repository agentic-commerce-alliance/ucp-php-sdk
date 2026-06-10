<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Contract\TokenizationCapabilityInterface;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

/**
 * Optional convenience wrapper for host applications that prefer small platform adapters
 * and a separate capability descriptor. Projects may implement TokenizationCapabilityInterface
 * directly instead.
 */
final class AdapterBackedTokenizationCapability implements TokenizationCapabilityInterface
{
    public function __construct(
        private readonly CapabilityDescriptor $descriptor,
        private readonly PaymentAdapterInterface $adapter,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return $this->descriptor;
    }

    public function tokenize(PaymentInstrument $instrument, RequestContext $context): array
    {
        $result = $this->adapter->tokenize($instrument, $context);

        return $result ?? [
            'status' => 'handler_declined',
            'handler_id' => $instrument->handlerId,
        ];
    }
}
