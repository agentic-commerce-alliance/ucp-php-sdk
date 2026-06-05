<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\RequestContext;

/**
 * Payment handler metadata and preparation hooks.
 *
 * Reference: https://ucp.dev/specification/payment-handler-guide/
 */
interface PaymentHandlerInterface
{
    public function id(): string;

    public function describe(RequestContext $context): PaymentHandlerDescriptor;

    /**
     * @return array{paymentMethodId: string, token: string, displayLast4?: string, displayBrand?: string}
     */
    public function prepareInstrument(PaymentInstrument $instrument, RequestContext $context): array;

    public function supportsTokenization(): bool;

    /**
     * @return array<string, mixed>|null
     */
    public function tokenize(PaymentInstrument $instrument, RequestContext $context): ?array;
}
