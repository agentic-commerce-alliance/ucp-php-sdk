<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Identity;

final class OAuthTokenRequest
{
    public function __construct(
        public readonly string $grantType,
        public readonly ?string $code = null,
        public readonly ?string $refreshToken = null,
        public readonly ?string $clientId = null,
        public readonly ?string $clientSecret = null,
        public readonly ?string $codeVerifier = null,
        public readonly ?string $redirectUri = null,
    ) {
    }
}
