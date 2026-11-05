<?php

declare(strict_types=1);

namespace Ucp\Sdk\Repository;

/**
 * State behind the discovery budget: how many profiles of unrecognized platforms were fetched
 * in the current window, and which origins are backing off after failures.
 *
 * The specification asks businesses for a fixed discovery footprint, so that resolving unknown
 * platforms costs the same however many of them ask. This contract holds the two counters that
 * bound it; the fetcher decides what the numbers mean.
 */
interface DiscoveryBudgetRepositoryInterface
{
    /**
     * Counts one fetch attempt in the window that starts at `$windowStart`, and returns the
     * number of attempts that window has seen so far, this one included.
     */
    public function countFetch(int $windowStart): int;

    /**
     * The Unix timestamp until which `$origin` is in backoff, or null when it may be fetched.
     */
    public function retryAfter(string $origin): ?int;

    /**
     * Records a failed fetch of `$origin` with the Unix timestamp until which it stays in
     * backoff, and returns the number of failures in a row, this one included.
     */
    public function recordFailure(string $origin, int $retryAfter): int;

    /**
     * Moves the backoff deadline of `$origin`, once its failure count is known.
     */
    public function extendBackoff(string $origin, int $retryAfter): void;

    /**
     * Forgets the failures of `$origin` after a successful fetch.
     */
    public function clearFailures(string $origin): void;

    public function purgeExpired(int $olderThanUnixTimestamp): void;
}
