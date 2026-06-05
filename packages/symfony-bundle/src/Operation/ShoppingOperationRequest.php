<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Operation;

use Ucp\Sdk\Model\RequestContext;

final readonly class ShoppingOperationRequest
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $operation,
        public array $payload,
        public RequestContext $context,
        public ?string $id = null,
    ) {
    }
}
