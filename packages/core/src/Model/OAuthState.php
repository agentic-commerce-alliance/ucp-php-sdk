<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model;

final class OAuthState
{
    public function __construct(
        public readonly string $code,
        public readonly string $clientId,
        public readonly string $subject,
        public readonly ?string $refreshToken = null,
        public readonly ?int $expiresAt = null,
    ) {
    }
}
