<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Security;

final class SignatureVerificationResult
{
    public function __construct(
        public readonly bool $verified,
        public readonly ?string $kid = null,
        public readonly ?string $algorithm = null,
        public readonly ?int $created = null,
        public readonly ?int $expires = null,
        public readonly bool $contentDigestVerified = false,
        public readonly bool $replayChecked = false,
        public readonly ?string $failureReason = null,
    ) {
    }
}
