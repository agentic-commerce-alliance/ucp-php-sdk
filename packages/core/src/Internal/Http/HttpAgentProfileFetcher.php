<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Http;

use Ucp\Sdk\Exception\AgentProfileException;
use Ucp\Sdk\Exception\UcpException;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;
use Ucp\Sdk\Model\Profile\CachedPlatformProfile;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Repository\PlatformProfileCacheRepositoryInterface;
use Ucp\Sdk\Repository\RevalidatingPlatformProfileCacheRepositoryInterface;
use Ucp\Sdk\Service\HttpClientInterface;
use Ucp\Sdk\Service\RequestScopedAgentProfileFetcherInterface;

/**
 * Fetches a platform's profile and caches it the way the platform asks to be cached.
 *
 * The profile's `Cache-Control: max-age` (or `s-maxage`, which wins for a shared cache like this
 * one) decides how long the entry is fresh, bounded below by the specification's floor of 60
 * seconds and above by `platform_profile_cache_ttl`. A profile that sends no such header, or a
 * cache repository that cannot store freshness, gets the configured TTL -- which is exactly the
 * behaviour this class had before it started listening. When the entry goes stale and the platform
 * sent an `ETag`, the refetch carries `If-None-Match`, and a 304 renews the entry without a body.
 *
 * `no-store` and `no-cache` are treated as the floor rather than as "never cache". The
 * specification requires profiles to be cacheable, and a fetch per request would turn every
 * platform request into a request to the platform.
 *
 * @internal
 */
final class HttpAgentProfileFetcher implements RequestScopedAgentProfileFetcherInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PlatformProfileCacheRepositoryInterface $cacheRepository,
        private readonly UrlSafetyValidator $urlSafetyValidator,
        private readonly int $timeoutSeconds = 5,
        private readonly int $maxResponseBytes = 1048576,
        private readonly int $maxTtlSeconds = 600,
        private readonly int $minimumTtlSeconds = 60,
    ) {
    }

    public function fetch(string $uri): PlatformProfile
    {
        return $this->fetchForAllowedHosts($uri, []);
    }

    public function fetchForAllowedHosts(string $uri, array $allowedProfileHosts): PlatformProfile
    {
        $validatedUri = $this->urlSafetyValidator->validateAndResolve(
            $uri,
            $allowedProfileHosts === [] ? null : $allowedProfileHosts,
        );

        $entry = $this->cachedEntry($uri);
        if ($entry !== null && $entry->isFresh()) {
            return $entry->profile;
        }

        $stale = $entry?->profile ?? $this->staleProfile($uri);

        try {
            $requestHeaders = ['Accept' => 'application/json'];
            if ($entry?->etag !== null) {
                $requestHeaders['If-None-Match'] = $entry->etag;
            }

            $response = $this->httpClient->request('GET', $uri, [
                'headers' => $requestHeaders,
                'timeout' => $this->timeoutSeconds,
                'max_redirects' => 0,
                'buffer' => false,
                'resolve' => $validatedUri->resolveMap(),
            ]);

            $headers = $response->getHeaders(false);

            if ($response->getStatusCode() === 304 && $entry !== null) {
                // The representation we hold is still the platform's. Renew its freshness from
                // this response's directives; a 304 may carry a new ETag as well.
                $this->store($uri, $entry->profile, $headers, $entry->etag);

                return $entry->profile;
            }

            if ($response->getStatusCode() !== 200) {
                throw AgentProfileException::unavailable($uri, $response->getStatusCode());
            }

            $contentLength = isset($headers['content-length'][0]) ? (int) $headers['content-length'][0] : null;
            if ($contentLength !== null && $contentLength > $this->maxResponseBytes) {
                throw AgentProfileException::tooLarge($uri, $this->maxResponseBytes);
            }

            $content = '';
            foreach ($this->httpClient->stream($response, $this->timeoutSeconds) as $chunk) {
                if ($chunk->isTimeout() || $chunk->isFirst()) {
                    continue;
                }

                $content .= $chunk->getContent();
                if (strlen($content) > $this->maxResponseBytes) {
                    $response->cancel();

                    throw AgentProfileException::tooLarge($uri, $this->maxResponseBytes);
                }
            }

            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw AgentProfileException::invalid($uri, 'the response body does not decode to a JSON object.');
            }

            $profile = PlatformProfile::fromArray($payload);
            $this->store($uri, $profile, $headers, null);

            return $profile;
        } catch (\Throwable $exception) {
            if ($stale !== null) {
                return $stale;
            }

            if ($exception instanceof UcpException) {
                throw $exception;
            }

            if ($exception instanceof \JsonException) {
                throw AgentProfileException::invalid($uri, $exception->getMessage(), $exception);
            }

            throw AgentProfileException::unreachable($uri, $exception);
        }
    }

    /**
     * The stale fallback for a repository without freshness metadata. A revalidating repository
     * has already answered through findEntry(), stale entry included, so there is nothing to ask.
     */
    private function staleProfile(string $uri): ?PlatformProfile
    {
        if ($this->cacheRepository instanceof RevalidatingPlatformProfileCacheRepositoryInterface) {
            return null;
        }

        return $this->cacheRepository->find($uri, true);
    }

    private function cachedEntry(string $uri): ?CachedPlatformProfile
    {
        if ($this->cacheRepository instanceof RevalidatingPlatformProfileCacheRepositoryInterface) {
            return $this->cacheRepository->findEntry($uri);
        }

        $fresh = $this->cacheRepository->find($uri);

        // A repository without freshness metadata can only answer fresh-or-not; when it says
        // fresh, any expiry in the future stands in for the one it will not tell us.
        return $fresh === null ? null : new CachedPlatformProfile($fresh, PHP_INT_MAX);
    }

    /**
     * @param array<string, list<string>> $responseHeaders
     */
    private function store(string $uri, PlatformProfile $profile, array $responseHeaders, ?string $previousEtag): void
    {
        if (! $this->cacheRepository instanceof RevalidatingPlatformProfileCacheRepositoryInterface) {
            $this->cacheRepository->save($uri, $profile);

            return;
        }

        $etag = isset($responseHeaders['etag'][0]) ? trim($responseHeaders['etag'][0]) : $previousEtag;

        $this->cacheRepository->saveEntry(
            $uri,
            $profile,
            time() + $this->freshnessSeconds($responseHeaders['cache-control'] ?? []),
            $etag === '' ? null : $etag,
        );
    }

    /**
     * The platform's freshness lifetime, clamped to [minimum, maximum]. Absent directives mean
     * the maximum: the platform said nothing, so the operator's setting decides.
     *
     * @param list<string> $cacheControl
     */
    private function freshnessSeconds(array $cacheControl): int
    {
        $directives = strtolower(implode(',', $cacheControl));

        if ($directives === '') {
            return $this->maxTtlSeconds;
        }

        // s-maxage overrides max-age for a shared cache (RFC 9111 section 5.2.2.10).
        foreach (['s-maxage', 'max-age'] as $directive) {
            if (preg_match('/(?:^|[\s,])' . preg_quote($directive, '/') . '\s*=\s*"?(\d+)"?/', $directives, $matches) === 1) {
                return max($this->minimumTtlSeconds, min($this->maxTtlSeconds, (int) $matches[1]));
            }
        }

        if (preg_match('/(?:^|[\s,])(?:no-store|no-cache)(?:$|[\s,])/', $directives) === 1) {
            return $this->minimumTtlSeconds;
        }

        return $this->maxTtlSeconds;
    }
}
