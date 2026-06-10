<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model;

use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Negotiation\NegotiatedCapabilities;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Security\MerchantAuthorizationVerificationResult;
use Ucp\Sdk\Model\Security\SignatureVerificationResult;

final class RequestContext
{
    /**
     * @param array<string, string> $headers
     * @param list<string> $negotiatedCapabilities
     */
    public function __construct(
        public readonly string $host,
        public readonly array $headers = [],
        public readonly ?string $platformProfileUri = null,
        public readonly ?PlatformProfile $platformProfile = null,
        public readonly array $negotiatedCapabilities = [],
        public readonly bool $signatureVerified = false,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $oauthClientId = null,
        public readonly ?RuntimeConfiguration $runtimeConfiguration = null,
        public readonly ?NegotiatedCapabilities $negotiation = null,
        public readonly ?SignatureVerificationResult $requestSignatureVerification = null,
        public readonly ?MerchantAuthorizationVerificationResult $merchantAuthorizationVerification = null,
        public readonly ?string $negotiationSessionId = null,
    ) {
    }
}
