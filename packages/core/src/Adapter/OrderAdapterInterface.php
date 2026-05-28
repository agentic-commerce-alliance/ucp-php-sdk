<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\RequestContext;

/**
 * Optional platform-facing contract for order read mapping.
 *
 * Projects may implement OrderCapabilityInterface directly instead when they do not
 * need a separate adapter layer.
 */
interface OrderAdapterInterface
{
    public function getOrder(string $id, RequestContext $context): OrderView;
}
