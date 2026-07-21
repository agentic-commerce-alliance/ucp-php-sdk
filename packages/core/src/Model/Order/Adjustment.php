<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Order;

use Ucp\Sdk\Enum\AdjustmentStatus;
use Ucp\Sdk\Model\Common\Money;

final class Adjustment
{
    /**
     * @param list<AdjustmentLineItem> $lineItems
     * @param list<Money> $totals
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $occurredAt,
        public readonly AdjustmentStatus $status,
        public readonly array $lineItems = [],
        public readonly array $totals = [],
        public readonly ?string $description = null,
        public readonly array $extra = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'type' => $this->type,
            'occurred_at' => $this->occurredAt,
            'status' => $this->status->value,
        ];

        if ([] !== $this->lineItems) {
            $data['line_items'] = array_map(static fn (AdjustmentLineItem $lineItem): array => $lineItem->toArray(), $this->lineItems);
        }

        if ([] !== $this->totals) {
            $data['totals'] = array_map(static fn (Money $total): array => $total->toArray(), $this->totals);
        }

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        return array_merge($data, $this->extra);
    }
}
