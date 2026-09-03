<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Operation;

use Ucp\Sdk\Model\RequestContext;

/**
 * One shopping operation to run: what, with which payload, in whose context.
 *
 * The input to ShoppingOperationExecutor, and public for the same reason.
 *
 * `$id` carries the resource identifier for operations that take it from the transport rather
 * than the payload -- a REST path segment, an MCP tool argument -- so `cart.get` and friends do
 * not require callers to duplicate it into `$payload`.
 */
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
