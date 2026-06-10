<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Operation;

use Ucp\Sdk\Model\RequestContext;

final class ShoppingOperationRequest
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $operation,
        public readonly array $payload,
        public readonly RequestContext $context,
        public readonly ?string $id = null,
    ) {
    }
}
