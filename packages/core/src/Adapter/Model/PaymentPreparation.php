<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter\Model;

final readonly class PaymentPreparation
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $paymentMethodId,
        public string $token,
        public ?string $displayLast4 = null,
        public ?string $displayBrand = null,
        public array $extra = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(array_filter([
            'paymentMethodId' => $this->paymentMethodId,
            'token' => $this->token,
            'displayLast4' => $this->displayLast4,
            'displayBrand' => $this->displayBrand,
        ], static fn (mixed $value): bool => $value !== null), $this->extra);
    }
}
