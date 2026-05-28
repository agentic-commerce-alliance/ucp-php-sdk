<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\RequestContext;

interface OrderCapabilityInterface extends CapabilityInterface
{
    public function getOrder(string $id, RequestContext $context): OrderView;
}
