<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Security;

final class MerchantAuthorizationVerificationResult
{
    /**
     * @param array<string, mixed> $claims
     */
    public function __construct(
        public readonly bool $verified,
        public readonly ?string $issuer = null,
        public readonly ?string $algorithm = null,
        public readonly array $claims = [],
        public readonly ?string $failureReason = null,
    ) {
    }
}
