<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Contract\OrderCapabilityInterface;
use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

/**
 * Optional convenience wrapper for host applications that prefer small platform adapters
 * and a separate capability descriptor. Projects may implement OrderCapabilityInterface
 * directly instead.
 */
final class AdapterBackedOrderCapability implements OrderCapabilityInterface
{
    public function __construct(
        private readonly CapabilityDescriptor $descriptor,
        private readonly OrderAdapterInterface $adapter,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return $this->descriptor;
    }

    public function getOrder(string $id, RequestContext $context): OrderView
    {
        return $this->adapter->getOrder($id, $context);
    }
}
