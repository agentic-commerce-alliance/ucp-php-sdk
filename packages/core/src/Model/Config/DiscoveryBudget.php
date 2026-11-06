<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Config;

/**
 * The fixed discovery footprint for platforms that are not pre-approved.
 *
 * `maxFetchesPerWindow` fetches of unrecognized profiles are allowed per `windowSeconds`, across
 * all callers; a refused fetch answers with how long to wait for the next window. A profile URL
 * whose fetch failed is backed off for `backoffBaseSeconds`, doubled per consecutive failure, up
 * to `backoffMaxSeconds`. Pre-approved platforms are never counted.
 */
final class DiscoveryBudget
{
    public function __construct(
        public readonly int $maxFetchesPerWindow = 60,
        public readonly int $windowSeconds = 60,
        public readonly int $backoffBaseSeconds = 30,
        public readonly int $backoffMaxSeconds = 3600,
    ) {
    }

    public function windowStart(int $now): int
    {
        return $now - ($now % $this->windowSeconds);
    }

    public function backoffSeconds(int $consecutiveFailures): int
    {
        $exponent = max(0, min($consecutiveFailures - 1, 30));

        return (int) min($this->backoffMaxSeconds, $this->backoffBaseSeconds * (2 ** $exponent));
    }
}
