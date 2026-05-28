<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

/**
 * Optional platform-facing contract for payment tokenization helpers.
 *
 * Projects may implement TokenizationCapabilityInterface directly instead when they do
 * not need a separate adapter layer.
 */
interface PaymentAdapterInterface
{
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
