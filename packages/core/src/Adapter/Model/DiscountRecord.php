<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter\Model;

final readonly class DiscountRecord
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $code,
        public ?string $label = null,
        public ?float $amount = null,
        public array $extra = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(array_filter([
            'code' => $this->code,
            'label' => $this->label,
            'amount' => $this->amount,
        ], static fn (mixed $value): bool => $value !== null), $this->extra);
    }
}
