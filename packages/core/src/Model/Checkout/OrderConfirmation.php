<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

final readonly class OrderConfirmation
{
    public function __construct(
        public string $id,
        public ?string $permalinkUrl = null,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'permalink_url' => $this->permalinkUrl,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
