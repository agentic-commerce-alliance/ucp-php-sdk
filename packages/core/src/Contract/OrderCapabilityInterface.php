<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\RequestContext;

/**
 * Order read operations.
 *
 * Reference: https://ucp.dev/specification/order/
 */
interface OrderCapabilityInterface extends CapabilityInterface
{
    public function getOrder(string $id, RequestContext $context): OrderView;
}
