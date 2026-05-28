<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Identity;

final readonly class OAuthTokenResponse
{
    public function __construct(
        public string $accessToken,
        public string $tokenType = 'Bearer',
        public int $expiresIn = 3600,
        public ?string $refreshToken = null,
        public ?string $scope = null,
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
