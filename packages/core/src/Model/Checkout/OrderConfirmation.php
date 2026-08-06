<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

/**
 * The order a checkout produced, as `types/order_confirmation.json` defines it.
 *
 * That schema marks **both** properties required, so `permalinkUrl` is typed as one.
 * It used to be optional and dropped from `toArray()` when null, which made the only
 * invalid state this object has also the easiest one to build: a business without a
 * permalink configured emitted an order confirmation missing a required field, and
 * the SDK then rejected the shop's own response with `$ must match exactly one
 * allowed schema` — naming neither the object nor the field. A missing permalink is
 * now a type error where the object is built, not a validation failure two layers
 * later.
 */
final class OrderConfirmation
{
    public function __construct(
        public readonly string $id,
        /** Permalink to the order on the merchant site; absolute, since the schema declares `format: uri`. */
        public readonly string $permalinkUrl,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'permalink_url' => $this->permalinkUrl,
        ];
    }
}
