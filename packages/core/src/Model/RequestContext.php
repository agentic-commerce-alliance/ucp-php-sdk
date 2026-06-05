<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model;

use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Negotiation\NegotiatedCapabilities;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Security\MerchantAuthorizationVerificationResult;
use Ucp\Sdk\Model\Security\SignatureVerificationResult;

final readonly class RequestContext
{
    /**
     * @param array<string, string> $headers
     * @param list<string> $negotiatedCapabilities
     */
    public function __construct(
        public string $host,
        public array $headers = [],
        public ?string $platformProfileUri = null,
        public ?PlatformProfile $platformProfile = null,
        public array $negotiatedCapabilities = [],
        public bool $signatureVerified = false,
        public ?string $idempotencyKey = null,
        public ?string $oauthClientId = null,
        public ?RuntimeConfiguration $runtimeConfiguration = null,
        public ?NegotiatedCapabilities $negotiation = null,
        public ?SignatureVerificationResult $requestSignatureVerification = null,
        public ?MerchantAuthorizationVerificationResult $merchantAuthorizationVerification = null,
        public ?string $negotiationSessionId = null,
    ) {
    }
}
