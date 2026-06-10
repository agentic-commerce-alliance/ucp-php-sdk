<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Ucp;

use MerchantSymfonyApp\Support\MerchantSettings;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\RequestContext;

final class MerchantPaymentHandler implements PaymentHandlerInterface
{
    public function __construct(
        private readonly MerchantSettings $settings,
    ) {
    }

    public function id(): string
    {
        return 'merchant.card';
    }

    public function describe(RequestContext $context): PaymentHandlerDescriptor
    {
        return new PaymentHandlerDescriptor(
            $this->id(),
            $this->id(),
            '2026-04-08',
            'https://ucp.dev/specification/payment-handler-guide/',
            'https://ucp.dev/schemas/payments/delegate-payment.json',
            ['https://ucp.dev/schemas/shopping/types/card_payment_instrument.json'],
            [
                'merchant' => $this->settings->brandName,
                'supported_networks' => ['visa', 'mastercard', 'amex'],
            ],
        );
    }

    public function prepareInstrument(PaymentInstrument $instrument, RequestContext $context): array
    {
        $lastFour = is_string($instrument->credential['card_last4'] ?? null) ? $instrument->credential['card_last4'] : '0000';

        return [
            'paymentMethodId' => 'pm_' . substr(hash('sha256', json_encode($instrument->credential, JSON_THROW_ON_ERROR)), 0, 10),
            'token' => 'prepared_' . $lastFour,
            'displayLast4' => $lastFour,
            'displayBrand' => 'merchant-card',
        ];
    }

    public function supportsTokenization(): bool
    {
        return true;
    }

    public function tokenize(PaymentInstrument $instrument, RequestContext $context): ?array
    {
        if ($instrument->handlerId !== $this->id()) {
            return null;
        }

        $lastFour = is_string($instrument->credential['card_last4'] ?? null) ? $instrument->credential['card_last4'] : '0000';

        return [
            'token' => 'tok_' . $lastFour . '_' . substr(hash('sha1', $instrument->handlerId), 0, 6),
            'handler_id' => $instrument->handlerId,
            'type' => $instrument->type,
        ];
    }
}
