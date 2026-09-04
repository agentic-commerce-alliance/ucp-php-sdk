<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Ucp;

use Ucp\Sdk\Contract\TokenizationCapabilityInterface;
use Ucp\Sdk\Enum\UcpProtocolVersion;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final class MerchantTokenizationCapability implements TokenizationCapabilityInterface
{
    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor(
            'dev.ucp.shopping.payment_tokenization',
            UcpProtocolVersion::current()->value,
            'https://ucp.dev/specification/payment-token-exchange/',
            'https://ucp.dev/schemas/shopping/payment-tokenization.json',
            null,
            [
                'handler_ids' => ['merchant.card'],
            ],
        );
    }

    public function tokenize(PaymentInstrument $instrument, RequestContext $context): array
    {
        $lastFour = is_string($instrument->credential['card_last4'] ?? null) ? $instrument->credential['card_last4'] : '0000';

        return [
            'token' => 'tok_' . $lastFour . '_' . substr(hash('sha256', $instrument->handlerId . $instrument->type), 0, 8),
            'type' => $instrument->type,
            'handler_id' => $instrument->handlerId,
            'card_last4' => $lastFour,
        ];
    }
}
