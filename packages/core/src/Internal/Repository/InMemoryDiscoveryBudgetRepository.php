<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Repository;

use Ucp\Sdk\Repository\DiscoveryBudgetRepositoryInterface;

/**
 * Per-process discovery state. Bounds a single worker; shared storage is what makes the bound
 * hold across workers, so the bundle wires its DBAL repository instead.
 *
 * @internal
 */
final class InMemoryDiscoveryBudgetRepository implements DiscoveryBudgetRepositoryInterface
{
    /** @var array<int, int> */
    private array $fetchesByWindow = [];

    /** @var array<string, array{failures: int, retryAfter: int}> */
    private array $failuresByUri = [];

    public function countFetch(int $windowStart): int
    {
        $this->fetchesByWindow[$windowStart] = ($this->fetchesByWindow[$windowStart] ?? 0) + 1;

        return $this->fetchesByWindow[$windowStart];
    }

    public function retryAfter(string $uri): ?int
    {
        return $this->failuresByUri[$uri]['retryAfter'] ?? null;
    }

    public function recordFailure(string $uri, int $retryAfter): int
    {
        $failures = ($this->failuresByUri[$uri]['failures'] ?? 0) + 1;
        $this->failuresByUri[$uri] = ['failures' => $failures, 'retryAfter' => $retryAfter];

        return $failures;
    }

    public function extendBackoff(string $uri, int $retryAfter): void
    {
        if (isset($this->failuresByUri[$uri])) {
            $this->failuresByUri[$uri]['retryAfter'] = $retryAfter;
        }
    }

    public function clearFailures(string $uri): void
    {
        unset($this->failuresByUri[$uri]);
    }

    public function purgeExpired(int $olderThanUnixTimestamp): void
    {
        foreach (array_keys($this->fetchesByWindow) as $windowStart) {
            if ($windowStart < $olderThanUnixTimestamp) {
                unset($this->fetchesByWindow[$windowStart]);
            }
        }

        foreach ($this->failuresByUri as $uri => $entry) {
            if ($entry['retryAfter'] < $olderThanUnixTimestamp) {
                unset($this->failuresByUri[$uri]);
            }
        }
    }
}
