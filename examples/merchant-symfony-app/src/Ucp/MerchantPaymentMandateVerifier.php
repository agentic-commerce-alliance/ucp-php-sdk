<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Ucp;

use Ucp\Sdk\Contract\PaymentMandateVerifierInterface;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

final class MerchantPaymentMandateVerifier implements PaymentMandateVerifierInterface
{
    public function verify(PaymentInstrument $instrument, RequestContext $context): void
    {
        if ($instrument->handlerId !== 'merchant.card') {
            throw new ValidationException('Unsupported payment handler for merchant example.', [
                sprintf('Expected handler "merchant.card", received "%s".', $instrument->handlerId),
            ]);
        }

        $token = $instrument->credential['token'] ?? null;

        // A reserved token so the failure path can be exercised end to end. A demo merchant
        // that only ever succeeds cannot show a caller what a declined payment looks like,
        // and the failure path is the one worth copying correctly.
        if ($token === 'fail_token') {
            throw new ValidationException('The payment was declined by the merchant example.', [
                'Payment token "fail_token" is reserved for demonstrating a declined payment.',
            ]);
        }

        $hasToken = is_string($token) && $token !== '';
        $hasLastFour = is_string($instrument->credential['card_last4'] ?? null) && preg_match('/^\d{4}$/', $instrument->credential['card_last4']) === 1;

        if (! $hasToken && ! $hasLastFour) {
            throw new ValidationException('Missing merchant payment credential.', [
                'Provide either a reusable token or a four-digit card suffix in the payment credential.',
            ]);
        }
    }
}
