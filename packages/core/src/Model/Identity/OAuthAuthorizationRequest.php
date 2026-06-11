<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Identity;

final class OAuthAuthorizationRequest
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $redirectUri,
        public readonly string $scope,
        public readonly string $state,
        public readonly ?string $codeChallenge = null,
        public readonly ?string $codeChallengeMethod = null,
    ) {
    }
}
