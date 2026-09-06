<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Identity;

final class OAuthMetadata
{
    /**
     * @param list<string> $scopesSupported
     * @param list<string> $grantTypesSupported
     * @param list<string> $tokenEndpointAuthMethodsSupported
     */
    public function __construct(
        public readonly string $issuer,
        public readonly string $authorizationEndpoint,
        public readonly string $tokenEndpoint,
        public readonly array $scopesSupported = [],
        public readonly array $grantTypesSupported = ['authorization_code', 'refresh_token'],
        public readonly array $tokenEndpointAuthMethodsSupported = ['client_secret_post', 'private_key_jwt'],
        /**
         * Advertised, not merely implemented.
         *
         * An agent reads `code_challenge_methods_supported` to decide whether it may use
         * PKCE at all; RFC 8414 says an omitted value means the server does not support it.
         * This metadata omitted the member entirely, so a correct client had to conclude
         * PKCE was unavailable and fall back to a bare authorization code -- the flow PKCE
         * exists to replace. `plain` is deliberately absent: it is a downgrade an
         * authorization server should not offer.
         *
         * @var list<string>
         */
        public readonly array $codeChallengeMethodsSupported = ['S256'],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->authorizationEndpoint,
            'token_endpoint' => $this->tokenEndpoint,
            'scopes_supported' => $this->scopesSupported,
            'grant_types_supported' => $this->grantTypesSupported,
            'token_endpoint_auth_methods_supported' => $this->tokenEndpointAuthMethodsSupported,
            'code_challenge_methods_supported' => $this->codeChallengeMethodsSupported,
        ];
    }
}
