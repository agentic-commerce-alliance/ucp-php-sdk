<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Ucp;

use MerchantSymfonyApp\Support\MerchantSettings;
use Ucp\Sdk\Contract\IdentityLinkingCapabilityInterface;
use Ucp\Sdk\Enum\UcpProtocolVersion;
use Ucp\Sdk\Exception\OAuthException;
use Ucp\Sdk\Model\Identity\OAuthAuthorizationRequest;
use Ucp\Sdk\Model\Identity\OAuthMetadata;
use Ucp\Sdk\Model\Identity\OAuthTokenRequest;
use Ucp\Sdk\Model\Identity\OAuthTokenResponse;
use Ucp\Sdk\Model\OAuthState;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Repository\OAuthStateRepositoryInterface;

final class MerchantIdentityLinkingCapability implements IdentityLinkingCapabilityInterface
{
    public function __construct(
        private readonly OAuthStateRepositoryInterface $oauthStateRepository,
        private readonly MerchantSettings $settings,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor(
            'dev.ucp.common.identity_linking',
            UcpProtocolVersion::current()->value,
            'https://ucp.dev/specification/identity-linking/',
            'https://ucp.dev/schemas/identity/oauth.json',
            null,
            [
                'scopes_supported' => ['profile', 'orders:write'],
                'pkce_required' => true,
            ],
        );
    }

    public function getMetadata(RequestContext $context): OAuthMetadata
    {
        return new OAuthMetadata(
            $this->settings->baseUri,
            $this->settings->baseUri . '/ucp/v1/oauth/authorize',
            $this->settings->baseUri . '/ucp/v1/oauth/token',
            ['profile', 'orders:write'],
            ['authorization_code'],
            ['none'],
        );
    }

    public function authorize(OAuthAuthorizationRequest $request, RequestContext $context): array
    {
        if ($request->clientId === '') {
            throw new OAuthException('Missing OAuth client ID.');
        }

        if ($request->redirectUri === '' || ! str_starts_with($request->redirectUri, 'http')) {
            throw new OAuthException('Invalid redirect URI.');
        }

        if ($request->codeChallenge === null) {
            throw new OAuthException('PKCE code challenge is required by the merchant example.');
        }

        $code = 'code_' . bin2hex(random_bytes(8));
        $subject = 'customer-1001';

        $this->oauthStateRepository->save(new OAuthState(
            $code,
            $request->clientId,
            $subject,
            'refresh_' . substr(hash('sha256', $code), 0, 12),
        ));

        $separator = str_contains($request->redirectUri, '?') ? '&' : '?';

        return [
            'client_id' => $request->clientId,
            'code' => $code,
            'state' => $request->state,
            'subject' => $subject,
            'redirect_to' => $request->redirectUri . $separator . http_build_query([
                'code' => $code,
                'state' => $request->state,
            ]),
        ];
    }

    public function issueToken(OAuthTokenRequest $request, RequestContext $context): OAuthTokenResponse
    {
        if ($request->grantType !== 'authorization_code') {
            throw new OAuthException('Only authorization_code is supported by the merchant example.');
        }

        if ($request->code === null || $request->code === '') {
            throw new OAuthException('Missing authorization code.');
        }

        $state = $this->oauthStateRepository->consume($request->code);
        if ($state === null) {
            throw new OAuthException('Authorization code is invalid or already consumed.');
        }

        if ($request->clientId !== null && $request->clientId !== $state->clientId) {
            throw new OAuthException('Client ID does not match the authorization code.');
        }

        return new OAuthTokenResponse(
            'access_' . substr(hash('sha256', $state->subject . $state->code), 0, 24),
            expiresIn: 3600,
            refreshToken: $state->refreshToken,
            scope: 'profile orders:write',
        );
    }
}
