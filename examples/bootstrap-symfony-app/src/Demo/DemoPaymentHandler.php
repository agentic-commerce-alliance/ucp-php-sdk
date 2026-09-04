<?php

declare(strict_types=1);

namespace BootstrapSymfonyApp\Demo;

use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Enum\UcpProtocolVersion;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\RequestContext;

final class DemoPaymentHandler implements PaymentHandlerInterface
{
    public function id(): string
    {
        return 'demo-handler';
    }

    public function describe(RequestContext $context): PaymentHandlerDescriptor
    {
        return new PaymentHandlerDescriptor(
            $this->id(),
            'com.demo.tokenizer',
            UcpProtocolVersion::current()->value,
            'https://ucp.dev/specification/payment-handler-guide/',
            'https://ucp.dev/schemas/payments/delegate-payment.json',
            ['https://ucp.dev/schemas/shopping/types/card_payment_instrument.json'],
            ['merchant' => 'demo'],
        );
    }

    public function prepareInstrument(PaymentInstrument $instrument, RequestContext $context): array
    {
        return [
            'paymentMethodId' => 'demo-method',
            'token' => 'prepared-token',
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

        return [
            'token' => 'tok-demo',
            'expires_at' => null,
        ];
    }
}
