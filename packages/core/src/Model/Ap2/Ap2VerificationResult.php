<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Ap2;

final class Ap2VerificationResult
{
    /**
     * @param array<string, mixed> $claims
     */
    public function __construct(
        public readonly bool $verified,
        public readonly array $claims = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $failureReason = null,
    ) {
    }

    /**
     * @param array<string, mixed> $claims
     */
    public static function verified(array $claims): self
    {
        return new self(true, $claims);
    }

    public static function failed(string $errorCode, string $failureReason): self
    {
        return new self(false, [], $errorCode, $failureReason);
    }
}
