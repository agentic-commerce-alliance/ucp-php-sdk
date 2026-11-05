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
    private array $failuresByOrigin = [];

    public function countFetch(int $windowStart): int
    {
        $this->fetchesByWindow[$windowStart] = ($this->fetchesByWindow[$windowStart] ?? 0) + 1;

        return $this->fetchesByWindow[$windowStart];
    }

    public function retryAfter(string $origin): ?int
    {
        return $this->failuresByOrigin[$origin]['retryAfter'] ?? null;
    }

    public function recordFailure(string $origin, int $retryAfter): int
    {
        $failures = ($this->failuresByOrigin[$origin]['failures'] ?? 0) + 1;
        $this->failuresByOrigin[$origin] = ['failures' => $failures, 'retryAfter' => $retryAfter];

        return $failures;
    }

    public function extendBackoff(string $origin, int $retryAfter): void
    {
        if (isset($this->failuresByOrigin[$origin])) {
            $this->failuresByOrigin[$origin]['retryAfter'] = $retryAfter;
        }
    }

    public function clearFailures(string $origin): void
    {
        unset($this->failuresByOrigin[$origin]);
    }

    public function purgeExpired(int $olderThanUnixTimestamp): void
    {
        foreach (array_keys($this->fetchesByWindow) as $windowStart) {
            if ($windowStart < $olderThanUnixTimestamp) {
                unset($this->fetchesByWindow[$windowStart]);
            }
        }

        foreach ($this->failuresByOrigin as $origin => $entry) {
            if ($entry['retryAfter'] < $olderThanUnixTimestamp) {
                unset($this->failuresByOrigin[$origin]);
            }
        }
    }
}
