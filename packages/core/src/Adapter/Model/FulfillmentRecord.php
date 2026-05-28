<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter\Model;

use Ucp\Sdk\Model\Checkout\FulfillmentSelection;

final readonly class FulfillmentRecord
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

    public function toFulfillmentSelection(): FulfillmentSelection
    {
        return new FulfillmentSelection($this->type, $this->methodId, $this->extra);
    }
}
