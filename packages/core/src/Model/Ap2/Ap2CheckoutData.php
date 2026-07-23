<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Ap2;

/**
 * Parsed `ap2` request data from a checkout completion payload.
 *
 * Reference: https://ucp.dev/latest/specification/ap2-mandates/
 */
final class Ap2CheckoutData
{
    public function __construct(
        public readonly ?string $checkoutMandate,
    ) {
    }
}
