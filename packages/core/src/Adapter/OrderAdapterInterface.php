<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Adapter\Model\OrderRecord;
use Ucp\Sdk\Model\RequestContext;

interface OrderAdapterInterface
{
    public function getOrder(string $id, RequestContext $context): OrderRecord;
}
