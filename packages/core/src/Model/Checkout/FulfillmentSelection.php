<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

final class FulfillmentSelection
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $methodId = null,
        public readonly array $extra = [],
    ) {
    }
}
