<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Http;

use Ucp\Sdk\Enum\PlatformPolicy;
use Ucp\Sdk\Internal\Repository\InMemoryDiscoveryBudgetRepository;
use Ucp\Sdk\Model\Config\DiscoveryBudget;
use Ucp\Sdk\Repository\DiscoveryBudgetRepositoryInterface;

/**
 * The discovery budget as the profile fetcher sees it.
 *
 * A platform outside the allowed hosts is "unrecognized" in the specification's sense, and
 * fetching its profile is discovery. The guard bounds it: a global number of fetches per
 * window, and a backoff per profile URL after failures. A listed platform is never discovery,
 * and a fresh cache hit never reaches the guard.
 *
 * The backoff is keyed on the full profile URL, not the host: keyed on the host, one unsigned
 * request naming a path that 404s would lock the platform's real profile out, and keep it out
 * with one request per backoff period. Per URL, every failure still costs a window slot, so the
 * number of backoff rows stays bounded by the window.
 *
 * @internal
 */
final class DiscoveryGuard
{
    public function __construct(
        private readonly DiscoveryBudgetRepositoryInterface $repository = new InMemoryDiscoveryBudgetRepository(),
        private readonly DiscoveryBudget $budget = new DiscoveryBudget(),
        public readonly PlatformPolicy $platformPolicy = PlatformPolicy::Listed,
    ) {
    }

    /**
     * Whether fetching `$host` is discovery, as opposed to serving a pre-approved platform.
     *
     * @param list<string> $allowedProfileHosts
     */
    public function isDiscovery(string $host, array $allowedProfileHosts): bool
    {
        $host = strtolower($host);
        foreach ($allowedProfileHosts as $allowedHost) {
            $allowedHost = strtolower($allowedHost);
            if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Seconds until `$uri` may be fetched, or null when it may be fetched now. A URL in backoff
     * is answered without touching the window; otherwise asking takes a slot, so a fetch the
     * window refuses still counts, as the one it would have been.
     */
    public function retryAfterSeconds(string $uri): ?int
    {
        $now = time();
        $retryAfter = $this->repository->retryAfter($uri);
        if ($retryAfter !== null && $retryAfter > $now) {
            return $retryAfter - $now;
        }

        $windowStart = $this->budget->windowStart($now);
        if ($this->repository->countFetch($windowStart) > $this->budget->maxFetchesPerWindow) {
            return $windowStart + $this->budget->windowSeconds - $now;
        }

        return null;
    }

    public function recordFailure(string $uri): void
    {
        $now = time();
        // The first failure's backoff is written with the count; a repeat failure only learns
        // its count from the write, so its longer backoff is set afterwards.
        $failures = $this->repository->recordFailure($uri, $now + $this->budget->backoffSeconds(1));
        if ($failures > 1) {
            $this->repository->extendBackoff($uri, $now + $this->budget->backoffSeconds($failures));
        }
    }

    public function recordSuccess(string $uri): void
    {
        $this->repository->clearFailures($uri);
    }
}
