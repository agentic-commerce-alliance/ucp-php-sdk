<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Http;

use Ucp\Sdk\Enum\PlatformPolicy;
use Ucp\Sdk\Exception\AgentProfileException;
use Ucp\Sdk\Internal\Repository\InMemoryDiscoveryBudgetRepository;
use Ucp\Sdk\Model\Config\DiscoveryBudget;
use Ucp\Sdk\Repository\DiscoveryBudgetRepositoryInterface;

/**
 * The discovery budget as the profile fetcher sees it.
 *
 * A platform outside the allowed hosts is "unrecognized" in the specification's sense, and
 * fetching its profile is discovery. The guard bounds it: a global number of fetches per
 * window, and a per-origin backoff after failures. A listed platform is never discovery, and
 * the fetcher asks the guard only for cold fetches, so a cache hit never counts either.
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
     * @throws AgentProfileException when the origin is in backoff or the window is full
     */
    public function assertAllowed(string $uri, string $origin): void
    {
        $now = time();
        $retryAfter = $this->repository->retryAfter($origin);
        if ($retryAfter !== null && $retryAfter > $now) {
            throw AgentProfileException::discoveryBudgetExceeded($uri, $retryAfter - $now);
        }

        $windowStart = $this->budget->windowStart($now);
        if ($this->repository->countFetch($windowStart) > $this->budget->maxFetchesPerWindow) {
            throw AgentProfileException::discoveryBudgetExceeded($uri, $windowStart + $this->budget->windowSeconds - $now);
        }
    }

    public function recordFailure(string $origin): void
    {
        $now = time();
        // The first failure's backoff is written with the count; a repeat failure only learns
        // its count from the write, so its longer backoff is set afterwards.
        $failures = $this->repository->recordFailure($origin, $now + $this->budget->backoffSeconds(1));
        if ($failures > 1) {
            $this->repository->extendBackoff($origin, $now + $this->budget->backoffSeconds($failures));
        }
    }

    public function recordSuccess(string $origin): void
    {
        $this->repository->clearFailures($origin);
    }
}
