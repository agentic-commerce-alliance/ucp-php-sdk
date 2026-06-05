<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Security;

final readonly class SignatureVerificationResult
{
    public function __construct(
        public bool $verified,
        public ?string $kid = null,
        public ?string $algorithm = null,
        public ?int $created = null,
        public ?int $expires = null,
        public bool $contentDigestVerified = false,
        public bool $replayChecked = false,
        public ?string $failureReason = null,
    ) {
    }
}
