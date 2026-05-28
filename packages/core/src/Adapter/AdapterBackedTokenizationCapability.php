<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Contract\TokenizationCapabilityInterface;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final readonly class AdapterBackedTokenizationCapability implements TokenizationCapabilityInterface
{
    public function __construct(
        private CapabilityDescriptor $descriptor,
        private PaymentAdapterInterface $adapter,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return $this->descriptor;
    }

    public function tokenize(PaymentInstrument $instrument, RequestContext $context): array
    {
        $result = $this->adapter->tokenize($instrument, $context);

        return $result?->toArray() ?? [
            'status' => 'handler_declined',
            'handler_id' => $instrument->handlerId,
        ];
    }
}
