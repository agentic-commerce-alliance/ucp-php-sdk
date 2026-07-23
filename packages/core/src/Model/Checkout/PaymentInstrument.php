<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

use Ucp\Sdk\Model\Common\PostalAddress;

final class PaymentInstrument
{
    /**
     * @param array<string, mixed> $credential
     * @param array<string, mixed>|null $display
     */
    public function __construct(
        public readonly string $type,
        public readonly string $handlerId,
        public readonly array $credential = [],
        public readonly ?string $id = null,
        public readonly ?bool $selected = null,
        public readonly ?PostalAddress $billingAddress = null,
        public readonly ?array $display = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'type' => $this->type,
            'handler_id' => $this->handlerId,
        ];

        if ($this->id !== null) {
            $data['id'] = $this->id;
        }

        if ($this->credential !== []) {
            $data['credential'] = $this->credential;
        }

        if ($this->billingAddress !== null) {
            $data['billing_address'] = $this->billingAddress->toArray();
        }

        if ($this->display !== null) {
            $data['display'] = $this->display;
        }

        if ($this->selected !== null) {
            $data['selected'] = $this->selected;
        }

        return $data;
    }
}
