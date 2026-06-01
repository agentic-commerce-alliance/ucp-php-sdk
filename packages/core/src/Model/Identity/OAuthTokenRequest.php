<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Identity;

final readonly class OAuthTokenRequest
{
    public function __construct(
        public string $grantType,
        public ?string $code = null,
        public ?string $refreshToken = null,
        public ?string $clientId = null,
        public ?string $clientSecret = null,
        public ?string $codeVerifier = null,
        public ?string $redirectUri = null,
    ) {
    }
}
