<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Contract\IdentityLinkingCapabilityInterface;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Identity\OAuthAuthorizationRequest;
use Ucp\Sdk\Model\Identity\OAuthMetadata;
use Ucp\Sdk\Model\Identity\OAuthTokenRequest;
use Ucp\Sdk\Model\Identity\OAuthTokenResponse;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;
use Ucp\Sdk\Symfony\Controller\OAuthController;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

/**
 * Covers the two ways identity linking can be unavailable.
 *
 * A merchant that never registered the capability and one that switched it off both
 * have to refuse, and they refuse through different guards -- an `instanceof` check and
 * a runtime-configuration check. Neither was exercised, which matters more here than on
 * the shopping controllers: this endpoint stands in front of an OAuth token, so a guard
 * that silently stopped guarding would issue tokens for a capability the merchant
 * believes is disabled.
 *
 * The happy paths run through the kernel integration tests; these are the refusals.
 */
final class OAuthControllerTest extends TestCase
{
    /**
     * `/.well-known/oauth-authorization-server` is unauthenticated, so it builds its own
     * context rather than reading one off the request. That means it reaches the guard by
     * a different route from authorize() and token(), and is worth asserting separately.
     */
    #[Test]
    public function metadataIsRefusedWhenNoIdentityLinkingCapabilityIsRegistered(): void
    {
        $controller = $this->controller(capability: null);

        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessage('Identity linking capability is not registered.');

        $controller->metadata(Request::create('https://merchant.example/.well-known/oauth-authorization-server'));
    }

    #[Test]
    public function authorizeIsRefusedWhenNoIdentityLinkingCapabilityIsRegistered(): void
    {
        $controller = $this->controller(capability: null);

        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessage('Identity linking capability is not registered.');

        $controller->authorize($this->contextualRequest('https://merchant.example/ucp/v1/oauth/authorize'));
    }

    #[Test]
    public function theTokenEndpointIsRefusedWhenNoIdentityLinkingCapabilityIsRegistered(): void
    {
        $controller = $this->controller(capability: null);

        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessage('Identity linking capability is not registered.');

        $controller->token($this->contextualRequest('https://merchant.example/ucp/v1/oauth/token'));
    }

    /**
     * The other guard: the capability is registered, so the instanceof check passes, and
     * the refusal has to come from the enabled-capability list instead. The distinct
     * message is what proves which of the two refused -- an operator reading "not
     * registered" would go looking for a missing service rather than a configuration
     * entry.
     */
    #[Test]
    public function theTokenEndpointIsRefusedWhenIdentityLinkingIsDisabledByConfiguration(): void
    {
        $controller = $this->controller(
            capability: new StubIdentityLinkingCapability(),
            enabledCapabilities: ['dev.ucp.shopping.cart'],
        );

        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessage('Identity linking capability is disabled by runtime configuration.');

        $controller->token($this->contextualRequest(
            'https://merchant.example/ucp/v1/oauth/token',
            enabledCapabilities: ['dev.ucp.shopping.cart'],
        ));
    }

    /**
     * An enabled-capability list that names identity linking must not refuse. Without
     * this, a guard that refused unconditionally would satisfy every assertion above.
     */
    #[Test]
    public function theTokenEndpointAnswersWhenIdentityLinkingIsAmongTheEnabledCapabilities(): void
    {
        $controller = $this->controller(
            capability: new StubIdentityLinkingCapability(),
            enabledCapabilities: ['dev.ucp.common.identity_linking'],
        );

        $response = $controller->token($this->contextualRequest(
            'https://merchant.example/ucp/v1/oauth/token',
            enabledCapabilities: ['dev.ucp.common.identity_linking'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('access-token-1', (string) $response->getContent());
    }

    /**
     * @param list<string> $enabledCapabilities
     */
    private function contextualRequest(string $uri, array $enabledCapabilities = []): Request
    {
        $request = Request::create($uri, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'grant_type' => 'authorization_code',
            'code' => 'code-1',
        ], JSON_THROW_ON_ERROR));

        $request->attributes->set('ucp_request_context', new RequestContext(
            'merchant.example',
            runtimeConfiguration: $this->runtimeConfiguration($enabledCapabilities),
        ));

        return $request;
    }

    /**
     * @param list<string> $enabledCapabilities
     */
    private function controller(
        ?IdentityLinkingCapabilityInterface $capability,
        array $enabledCapabilities = [],
    ): OAuthController {
        return new OAuthController(
            new SingleCapabilityRegistry($capability),
            new HttpPayloadMapper(),
            $this->responseFactory(),
            new FixedRuntimeConfigurationResolver($this->runtimeConfiguration($enabledCapabilities)),
        );
    }

    /**
     * @param list<string> $enabledCapabilities
     */
    private function runtimeConfiguration(array $enabledCapabilities): RuntimeConfiguration
    {
        return new RuntimeConfiguration(
            '2026-04-08',
            'https://merchant.example',
            enabledCapabilities: $enabledCapabilities,
        );
    }

    private function responseFactory(): UcpResponseFactory
    {
        return new UcpResponseFactory(new UcpSdkConfiguration(
            '2026-04-08',
            'https://merchant.example',
            [],
            'log',
            [],
            false,
            86400,
            262144,
            600,
            604800,
            300,
            600,
            [],
            false,
            'default',
            'ES256',
            'P30D',
            'P30D',
            262144,
            10,
            false,
            'sqlite:///%kernel.project_dir%/var/ucp_sdk.sqlite',
        ));
    }
}

final class StubIdentityLinkingCapability implements IdentityLinkingCapabilityInterface
{
    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor('dev.ucp.common.identity_linking', '2026-04-08', 'spec', 'schema');
    }

    public function getMetadata(RequestContext $context): OAuthMetadata
    {
        return new OAuthMetadata(
            'https://merchant.example',
            'https://merchant.example/ucp/v1/oauth/authorize',
            'https://merchant.example/ucp/v1/oauth/token',
        );
    }

    public function authorize(OAuthAuthorizationRequest $request, RequestContext $context): array
    {
        return ['redirect_uri' => $request->redirectUri . '?code=code-1&state=' . $request->state];
    }

    public function issueToken(OAuthTokenRequest $request, RequestContext $context): OAuthTokenResponse
    {
        return new OAuthTokenResponse('access-token-1');
    }
}
