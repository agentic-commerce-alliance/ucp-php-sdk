<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Contract\IdentityLinkingCapabilityInterface;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\CapabilityRegistryInterface;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;

final class OAuthController
{
    public function __construct(
        private readonly CapabilityRegistryInterface $capabilityRegistry,
        private readonly HttpPayloadMapper $payloadMapper,
        private readonly UcpResponseFactory $responseFactory,
        private readonly RuntimeConfigurationResolverInterface $runtimeConfigurationResolver,
    ) {
    }

    #[Route(path: '/.well-known/oauth-authorization-server', methods: ['GET'])]
    public function metadata(Request $request): Response
    {
        return $this->responseFactory->success($this->requireCapability()->getMetadata($this->publicContext($request))->toArray());
    }

    #[Route(path: '/ucp/v1/oauth/authorize', methods: ['GET'])]
    public function authorize(Request $request): Response
    {
        $result = $this->requireCapability()->authorize($this->payloadMapper->toOAuthAuthorizationRequest($request), $request->attributes->get('ucp_request_context'));

        return $this->responseFactory->success($result);
    }

    #[Route(path: '/ucp/v1/oauth/token', methods: ['POST'])]
    public function token(Request $request): Response
    {
        $payload = $this->payloadMapper->decode($request);
        $result = $this->requireCapability()->issueToken($this->payloadMapper->toOAuthTokenRequest($payload), $request->attributes->get('ucp_request_context'));

        return $this->responseFactory->success($result->toArray());
    }

    private function requireCapability(): IdentityLinkingCapabilityInterface
    {
        $capability = $this->capabilityRegistry->firstImplementing(IdentityLinkingCapabilityInterface::class);
        if (! $capability instanceof IdentityLinkingCapabilityInterface) {
            throw new UnsupportedCapabilityException('Identity linking capability is not registered.');
        }

        return $capability;
    }

    private function publicContext(Request $request): RequestContext
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        $query = $request->query->all();
        ksort($query);

        $httpRequest = new HttpRequest(
            $request->getMethod(),
            $request->getUri(),
            $headers,
            array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR), $query),
            '',
        );

        return new RequestContext(
            parse_url($request->getUri(), PHP_URL_HOST) ?: '',
            $headers,
            runtimeConfiguration: $this->runtimeConfigurationResolver->resolve($httpRequest),
        );
    }
}
