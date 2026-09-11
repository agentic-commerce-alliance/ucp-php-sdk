<?php

declare(strict_types=1);

namespace BootstrapSymfonyApp\Demo;

use Ucp\Sdk\Contract\TokenizationCapabilityInterface;
use Ucp\Sdk\Enum\UcpProtocolVersion;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final class DemoTokenizationCapability implements TokenizationCapabilityInterface
{
    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor(
            'dev.ucp.shopping.payment_tokenization',
            UcpProtocolVersion::current()->value,
            'https://ucp.dev/specification/payment-token-exchange/',
            'https://ucp.dev/schemas/shopping/payment-tokenization.json',
        );
    }

    public function tokenize(PaymentInstrument $instrument, RequestContext $context): array
    {
        return [
            'token' => 'tok-demo',
            'type' => $instrument->type,
            'handler_id' => $instrument->handlerId,
        ];
    }
}
