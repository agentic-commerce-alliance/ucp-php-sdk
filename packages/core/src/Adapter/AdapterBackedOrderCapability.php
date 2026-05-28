<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Contract\OrderCapabilityInterface;
use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final readonly class AdapterBackedOrderCapability implements OrderCapabilityInterface
{
    public function __construct(
        private CapabilityDescriptor $descriptor,
        private OrderAdapterInterface $adapter,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return $this->descriptor;
    }

    public function getOrder(string $id, RequestContext $context): OrderView
    {
        return $this->adapter->getOrder($id, $context)->toOrderView();
    }
}
