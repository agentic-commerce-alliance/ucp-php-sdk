<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Order;

final class AdjustmentLineItem
{
    public function __construct(
        public readonly string $id,
        public readonly int $quantity,
    ) {
    }

    /**
     * @return array{id: string, quantity: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'quantity' => $this->quantity,
        ];
    }
}
