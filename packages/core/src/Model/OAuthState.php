<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model;

final readonly class OAuthState
{
    public function __construct(
        public string $code,
        public string $clientId,
        public string $subject,
        public ?string $refreshToken = null,
        public ?int $expiresAt = null,
    ) {
    }
}
