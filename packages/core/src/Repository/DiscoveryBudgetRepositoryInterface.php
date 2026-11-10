<?php

declare(strict_types=1);

namespace Ucp\Sdk\Repository;

/**
 * State behind the discovery budget: how many profiles of unrecognized platforms were fetched
 * in the current window, and which profile URLs are backing off after failures.
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
     * The Unix timestamp until which `$uri` is in backoff, or null when it may be fetched.
     */
    public function retryAfter(string $uri): ?int;

    /**
     * Records a failed fetch of `$uri` with the Unix timestamp until which it stays in
     * backoff, and returns the number of failures in a row, this one included.
     */
    public function recordFailure(string $uri, int $retryAfter): int;

    /**
     * Moves the backoff deadline of `$uri`, once its failure count is known.
     */
    public function extendBackoff(string $uri, int $retryAfter): void;

    /**
     * Forgets the failures of `$uri` after a successful fetch.
     */
    public function clearFailures(string $uri): void;

    public function purgeExpired(int $olderThanUnixTimestamp): void;
}
