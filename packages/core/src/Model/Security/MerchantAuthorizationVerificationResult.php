<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Security;

final readonly class MerchantAuthorizationVerificationResult
{
    /**
     * @param array<string, mixed> $claims
     */
    public function __construct(
        public bool $verified,
        public ?string $issuer = null,
        public ?string $algorithm = null,
        public array $claims = [],
        public ?string $failureReason = null,
    ) {
    }
}
