<?php

declare(strict_types=1);

namespace BootstrapSymfonyApp\Demo;

use Ucp\Sdk\Contract\IdentityLinkingCapabilityInterface;
use Ucp\Sdk\Enum\UcpProtocolVersion;
use Ucp\Sdk\Model\Identity\OAuthAuthorizationRequest;
use Ucp\Sdk\Model\Identity\OAuthMetadata;
use Ucp\Sdk\Model\Identity\OAuthTokenRequest;
use Ucp\Sdk\Model\Identity\OAuthTokenResponse;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final class DemoIdentityLinkingCapability implements IdentityLinkingCapabilityInterface
{
    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor(
            'dev.ucp.common.identity_linking',
            UcpProtocolVersion::current()->value,
            'https://ucp.dev/specification/identity-linking/',
            'https://ucp.dev/schemas/identity/oauth.json',
        );
    }

    public function getMetadata(RequestContext $context): OAuthMetadata
    {
        return new OAuthMetadata('https://example.com', 'https://example.com/ucp/v1/oauth/authorize', 'https://example.com/ucp/v1/oauth/token', ['profile', 'orders:write']);
    }

    public function authorize(OAuthAuthorizationRequest $request, RequestContext $context): array
    {
        return [
            'client_id' => $request->clientId,
            'redirect_uri' => $request->redirectUri,
            'scope' => $request->scope,
            'state' => $request->state,
            'authorization_code' => 'code-demo',
        ];
    }

    public function issueToken(OAuthTokenRequest $request, RequestContext $context): OAuthTokenResponse
    {
        return new OAuthTokenResponse('access-demo', refreshToken: 'refresh-demo', scope: 'profile orders:write');
    }
}
