<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Identity;

final class OAuthTokenResponse
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $tokenType = 'Bearer',
        public readonly int $expiresIn = 3600,
        public readonly ?string $refreshToken = null,
        public readonly ?string $scope = null,
    ) {
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return array_filter([
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'expires_in' => $this->expiresIn,
            'refresh_token' => $this->refreshToken,
            'scope' => $this->scope,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
