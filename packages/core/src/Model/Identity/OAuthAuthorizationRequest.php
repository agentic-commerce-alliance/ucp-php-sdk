<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Identity;

final readonly class OAuthAuthorizationRequest
{
    public function __construct(
        public string $clientId,
        public string $redirectUri,
        public string $scope,
        public string $state,
        public ?string $codeChallenge = null,
        public ?string $codeChallengeMethod = null,
    ) {
    }
}
