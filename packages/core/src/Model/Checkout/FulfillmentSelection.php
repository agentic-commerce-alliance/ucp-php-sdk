<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

final readonly class FulfillmentSelection
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $type,
        public ?string $methodId = null,
        public array $extra = [],
    ) {
    }
}
