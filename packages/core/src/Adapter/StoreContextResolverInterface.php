<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Model\RequestContext;

interface StoreContextResolverInterface
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(RequestContext $context): array;
}
