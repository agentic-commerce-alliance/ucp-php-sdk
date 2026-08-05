<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Service;

use Ucp\Sdk\Enum\SignaturePolicy;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Negotiation\NegotiationSession;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Repository\NegotiationSessionRepositoryInterface;
use Ucp\Sdk\Service\AgentProfileFetcherInterface;
use Ucp\Sdk\Service\CapabilityNegotiatorInterface;
use Ucp\Sdk\Service\HttpRequestContextFactoryInterface;
use Ucp\Sdk\Service\MerchantAuthorizationServiceInterface;
use Ucp\Sdk\Service\RequestScopedAgentProfileFetcherInterface;
use Ucp\Sdk\Service\RequestSignatureServiceInterface;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;

/** @internal */
final class DefaultHttpRequestContextFactory implements HttpRequestContextFactoryInterface
{
    public function __construct(
        private readonly RuntimeConfigurationResolverInterface $runtimeConfigurationResolver,
        private readonly AgentProfileFetcherInterface $agentProfileFetcher,
        private readonly RequestSignatureServiceInterface $requestSignatureService,
        private readonly CapabilityNegotiatorInterface $capabilityNegotiator,
        private readonly ?NegotiationSessionRepositoryInterface $negotiationSessionRepository = null,
        private readonly ?MerchantAuthorizationServiceInterface $merchantAuthorizationService = null,
    ) {
    }

    public function create(HttpRequest $request): RequestContext
    {
        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[strtolower($name)] = $value;
        }

        $configuration = $this->runtimeConfigurationResolver->resolve($request);
        $agentHeader = $headers['ucp-agent'] ?? null;
        if ($agentHeader === null || trim($agentHeader) === '') {
            throw new ValidationException(
                'UCP-Agent header with a profile URI is required for UCP runtime requests.',
                ['$.headers.ucp-agent is required'],
            );
        }

        $profileUri = $this->extractProfileUri($agentHeader);
        if ($profileUri === null || trim($profileUri) === '') {
            throw new ValidationException(
                'UCP-Agent header must include a non-empty profile URI.',
                ['$.headers.ucp-agent.profile must be a non-empty URI'],
            );
        }

        $this->assertSafeProfileUri(
            $profileUri,
            $configuration->allowedProfileHosts,
            $configuration->allowedAgentDomains,
            $configuration->profileFetchingDevelopmentMode,
        );

        $platformProfile = $this->agentProfileFetcher instanceof RequestScopedAgentProfileFetcherInterface
            ? $this->agentProfileFetcher->fetchForAllowedHosts($profileUri, $configuration->allowedProfileHosts)
            : $this->agentProfileFetcher->fetch($profileUri);
        $publicKeys = $platformProfile->signingKeys;
        $verificationResult = $this->requestSignatureService->verify($request, $publicKeys);

        if ($configuration->signaturePolicy === SignaturePolicy::Strict && ! $verificationResult->verified) {
            throw new SignatureException(
                $verificationResult->failureReason ?? 'Request signature verification failed.',
            );
        }

        $context = new RequestContext(
            parse_url($request->absoluteUri, PHP_URL_HOST) ?: '',
            $headers,
            $profileUri,
            $platformProfile,
            [],
            $verificationResult->verified,
            $headers['idempotency-key'] ?? null,
            $headers['x-oauth-client-id'] ?? null,
            $configuration,
        );

        $merchantAuthorizationVerification = null;
        if ($this->merchantAuthorizationService !== null) {
            $merchantAuthorizationVerification = $this->merchantAuthorizationService->verify($request, $publicKeys, $context);
        }

        $negotiation = $this->capabilityNegotiator->negotiate($platformProfile, $context);
        $sessionId = null;

        if ($this->negotiationSessionRepository !== null) {
            $existing = $this->negotiationSessionRepository->findByProfileUri($profileUri, $configuration->tenantIdentifier);
            $sessionId = $existing instanceof NegotiationSession
                ? $existing->id
                : 'neg_' . substr(hash('sha256', $profileUri . '|' . ($configuration->tenantIdentifier ?? 'default')), 0, 16);
            $this->negotiationSessionRepository->save(new NegotiationSession(
                $sessionId,
                $profileUri,
                $configuration->version,
                $negotiation->capabilityNames(),
                $negotiation->paymentHandlerIds,
                $configuration->tenantIdentifier,
                gmdate('c'),
            ));
        }

        return new RequestContext(
            $context->host,
            $context->headers,
            $context->platformProfileUri,
            $context->platformProfile,
            $negotiation->capabilityNames(),
            $context->signatureVerified,
            $context->idempotencyKey,
            $context->oauthClientId,
            $configuration,
            $negotiation,
            $verificationResult,
            $merchantAuthorizationVerification,
            $sessionId,
        );
    }

    private function extractProfileUri(?string $header): ?string
    {
        if ($header === null) {
            return null;
        }

        if (preg_match('/profile="([^"]+)"/', $header, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param list<string> $allowedProfileHosts
     * @param list<string> $allowedAgentDomains
     */
    private function assertSafeProfileUri(
        string $profileUri,
        array $allowedProfileHosts,
        array $allowedAgentDomains,
        bool $profileFetchingDevelopmentMode,
    ): void {
        $parts = parse_url($profileUri);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            throw new SignatureException('Platform profile URI must include a host.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SignatureException('Platform profile URI must not include userinfo.');
        }

        if ($allowedProfileHosts === []) {
            if ($profileFetchingDevelopmentMode && $this->isLocalDevelopmentProfileUri($scheme, $host)) {
                return;
            }

            throw new SignatureException('Platform profile host is not allowed by the current runtime configuration.');
        }

        $allowed = false;
        foreach ($allowedProfileHosts as $allowedHost) {
            $allowedHost = strtolower($allowedHost);
            if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
                $allowed = true;
                break;
            }
        }

        if (! $allowed) {
            throw new SignatureException('Platform profile host is not allowed by the current runtime configuration.');
        }

        if ($allowedAgentDomains !== []) {
            $allowed = false;
            foreach ($allowedAgentDomains as $allowedDomain) {
                $allowedDomain = strtolower($allowedDomain);
                if ($host === $allowedDomain || str_ends_with($host, '.' . $allowedDomain)) {
                    $allowed = true;
                    break;
                }
            }

            if (! $allowed) {
                throw new SignatureException('Platform agent domain is not allowed for the current runtime configuration.');
            }
        }
    }

    private function isLocalDevelopmentProfileUri(string $scheme, string $host): bool
    {
        return ($scheme === 'http' || $scheme === 'https') && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
