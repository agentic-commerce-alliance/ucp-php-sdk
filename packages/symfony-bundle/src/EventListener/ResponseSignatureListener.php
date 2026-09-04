<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Http\HttpResponse;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Repository\TenantAwareManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\ResponseSignatureServiceInterface;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

/**
 * Signs UCP responses on the way out.
 *
 * Registered at a negative priority so it runs after `IdempotencyResponseListener`. That
 * ordering is not cosmetic: a replayed response is produced by the idempotency layer, and a
 * replay that went out unsigned while the original was signed would be a hole a caller cannot
 * see -- the second answer to the same question would carry less proof than the first.
 *
 * @internal
 */
final class ResponseSignatureListener
{
    public function __construct(
        private readonly UcpSdkConfiguration $configuration,
        private readonly ResponseSignatureServiceInterface $responseSignatureService,
        private readonly ManagedSigningKeyRepositoryInterface $signingKeyRepository,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (! $this->configuration->responseSigningEnabled || ! $event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (! str_starts_with($request->getPathInfo(), '/ucp/')) {
            return;
        }

        $response = $event->getResponse();
        if ($response->headers->has('Signature')) {
            return;
        }

        $key = $this->activeKey($request);
        if ($key === null) {
            // Answering unsigned is better than answering 500: the caller asked for a checkout,
            // not for a signature, and a business with no key has a deployment problem rather
            // than a request problem. It is logged because silently serving unsigned responses
            // under a policy that promises signatures is its own kind of wrong.
            $this->logger?->warning('UCP response signing is enabled but no signing key is available.');

            return;
        }

        foreach ($this->responseSignatureService->sign(
            new HttpResponse(
                $response->getStatusCode(),
                $this->responseHeaders($response->headers->all()),
                (string) $response->getContent(),
            ),
            $this->toHttpRequest($request),
            $key,
        ) as $name => $value) {
            $response->headers->set($name, $value);
        }
    }

    private function activeKey(Request $request): ?ManagedSigningKey
    {
        // The tenant comes from the request context rather than from bundle configuration,
        // because a business serving several tenants signs each one's responses with that
        // tenant's key -- and the bundle configuration knows nothing about which one is being
        // answered right now.
        $context = $request->attributes->get('ucp_request_context');
        $tenant = $context instanceof RequestContext ? $context->runtimeConfiguration?->tenantIdentifier : null;

        $active = $this->signingKeyRepository instanceof TenantAwareManagedSigningKeyRepositoryInterface
            ? $this->signingKeyRepository->activeForTenant($tenant)
            : $this->signingKeyRepository->active();

        return $active[0] ?? null;
    }

    /**
     * @param array<string, list<string|null>> $headers
     *
     * @return array<string, string>
     */
    private function responseHeaders(array $headers): array
    {
        $flattened = [];
        foreach ($headers as $name => $values) {
            $value = $values[0] ?? null;
            if (is_string($value)) {
                $flattened[strtolower($name)] = $value;
            }
        }

        return $flattened;
    }

    private function toHttpRequest(Request $request): HttpRequest
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $value = $values[0] ?? null;
            if (is_string($value)) {
                $headers[strtolower($name)] = $value;
            }
        }

        return new HttpRequest($request->getMethod(), $request->getUri(), $headers);
    }
}
