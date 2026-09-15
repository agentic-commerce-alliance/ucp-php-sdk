<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Service;

use Ucp\Sdk\Enum\SignaturePolicy;
use Ucp\Sdk\Enum\VersionNegotiationOutcome;
use Ucp\Sdk\Event\VersionNegotiationObservedEvent;
use Ucp\Sdk\Exception\NegotiationException;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Internal\Security\AgentDomainAllowList;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Negotiation\NegotiationSession;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Repository\NegotiationSessionRepositoryInterface;
use Ucp\Sdk\Service\AgentProfileFetcherInterface;
use Ucp\Sdk\Service\CapabilityNegotiatorInterface;
use Ucp\Sdk\Service\EventDispatcherInterface;
use Ucp\Sdk\Service\HttpRequestContextFactoryInterface;
use Ucp\Sdk\Service\MerchantAuthorizationServiceInterface;
use Ucp\Sdk\Service\ProfileBuilderInterface;
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
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?ProfileBuilderInterface $profileBuilder = null,
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

        // The platform may name the protocol version it is speaking. Answering a version this
        // release does not serve, in the shapes of the one it does, is how two peers end up
        // disagreeing about a field neither of them will mention -- so it is refused up front,
        // where the reason is still the version rather than whatever fails first because of it.
        //
        // A `version` parameter on UCP-Agent is not in the specification; it is proposed in
        // upstream PR #793 and read here in case a platform sends it. A refusal on it never
        // reaches the executor's version check, so it is observed here or not at all. A match
        // is not observed here: that request goes on to the executor, which records the
        // profile's version once, and counting it twice would skew the histogram.
        $requestedVersion = $this->extractAgentParameter($agentHeader, 'version');
        if ($requestedVersion !== null && $requestedVersion !== $configuration->version) {
            $this->eventDispatcher?->dispatch(new VersionNegotiationObservedEvent(
                $requestedVersion,
                $configuration->version,
                $profileUri,
                VersionNegotiationOutcome::Rejected,
            ));

            throw NegotiationException::versionUnsupported(sprintf(
                'This business serves UCP %s; the request asked for %s.',
                $configuration->version,
                $requestedVersion,
            ));
        }

        $platformProfile = $this->ownProfileForDevelopment($profileUri, $request, $configuration);
        if ($platformProfile === null) {
            $this->assertSafeProfileUri(
                $profileUri,
                $configuration->allowedProfileHosts,
                $configuration->allowedAgentDomains,
                $configuration->profileFetchingDevelopmentMode,
            );

            $platformProfile = $this->agentProfileFetcher instanceof RequestScopedAgentProfileFetcherInterface
                ? $this->agentProfileFetcher->fetchForAllowedHosts($profileUri, $configuration->allowedProfileHosts)
                : $this->agentProfileFetcher->fetch($profileUri);
        }
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

    /**
     * In development mode, this business is an acceptable agent for itself.
     *
     * Every runtime request has to name an agent profile, and in production that profile lives
     * on the platform's own public host. Locally there is no such host: `*.localhost` names and
     * container hostnames resolve to loopback, which the SSRF rules refuse for good reason, so
     * the first request against a fresh install used to need a second web server just to hand
     * out a JSON document. The plugin's functional tests and the conformance lane both solved
     * this by negotiating against the business's *own* profile, and this makes that a supported
     * path: point `UCP-Agent` at this deployment's `/.well-known/ucp` and the profile is built
     * in-process, exactly as the discovery endpoint would serve it, with no fetch and no cache.
     *
     * Strictly development mode, and strictly the own discovery URL: the URI must carry that
     * path and its scheme, host and port must match either the request's own or the configured
     * base URI. Anything else takes the normal fetch path with all of its checks.
     */
    private function ownProfileForDevelopment(string $profileUri, HttpRequest $request, RuntimeConfiguration $configuration): ?PlatformProfile
    {
        if (! $configuration->profileFetchingDevelopmentMode || $this->profileBuilder === null) {
            return null;
        }

        $profile = parse_url($profileUri);
        if (! is_array($profile) || ($profile['path'] ?? '') !== '/.well-known/ucp' || isset($profile['user']) || isset($profile['pass'])) {
            return null;
        }

        $requestOrigin = self::origin($request->absoluteUri);
        $baseOrigin = $configuration->baseUri !== '' ? self::origin($configuration->baseUri) : null;
        $profileOrigin = self::origin($profileUri);

        if ($profileOrigin === null || ($profileOrigin !== $requestOrigin && $profileOrigin !== $baseOrigin)) {
            return null;
        }

        return $this->profileBuilder->build(new ProfileBuildInput(
            $configuration->version,
            $configuration->baseUri !== '' ? $configuration->baseUri : substr($profileUri, 0, -strlen('/.well-known/ucp')),
            $configuration->transports,
            supportedVersions: $configuration->supportedVersions,
            transportEndpoints: $configuration->transportEndpoints,
            tenantIdentifier: $configuration->tenantIdentifier,
            enabledCapabilities: $configuration->enabledCapabilities,
        ));
    }

    /**
     * `scheme://host:port`, lower-cased, with the scheme's default port made explicit so that
     * `https://shop.example` and `https://shop.example:443` compare equal.
     */
    private static function origin(string $uri): ?string
    {
        $parts = parse_url($uri);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return sprintf('%s://%s:%d', $scheme, strtolower($parts['host']), $port);
    }

    private function extractProfileUri(?string $header): ?string
    {
        return $this->extractAgentParameter($header, 'profile');
    }

    /**
     * A quoted parameter from the UCP-Agent header.
     */
    private function extractAgentParameter(?string $header, string $name): ?string
    {
        if ($header === null) {
            return null;
        }

        if (preg_match('/\b' . preg_quote($name, '/') . '="([^"]+)"/', $header, $matches) === 1) {
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

        // Shared with the embedded transport's CORS check rather than reimplemented. This
        // list used to be matched here as bare domains and there as full origins, so an
        // entry that satisfied one gate was refused by the other.
        if ($allowedAgentDomains !== [] && ! AgentDomainAllowList::matchesHost($host, $allowedAgentDomains)) {
            throw new SignatureException('Platform agent domain is not allowed for the current runtime configuration.');
        }
    }

    private function isLocalDevelopmentProfileUri(string $scheme, string $host): bool
    {
        return ($scheme === 'http' || $scheme === 'https') && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
