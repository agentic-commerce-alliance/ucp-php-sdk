<?php

declare(strict_types=1);

namespace Ucp\Sdk\Repository;

use Ucp\Sdk\Model\Profile\CachedPlatformProfile;
use Ucp\Sdk\Model\Profile\PlatformProfile;

/**
 * A platform-profile cache that can say how long an entry is good for and what validator it
 * carries, so the fetcher can honour the platform's own `Cache-Control` and revalidate with
 * `If-None-Match` instead of applying one blind TTL to every platform.
 *
 * Kept as an extension of {@see PlatformProfileCacheRepositoryInterface} rather than a change to
 * it: adding methods to a published interface breaks every implementation of it, and the fetcher
 * works with either -- a repository that implements only the base interface keeps the fixed
 * TTL it had.
 */
interface RevalidatingPlatformProfileCacheRepositoryInterface extends PlatformProfileCacheRepositoryInterface
{
    /**
     * The stored entry, fresh or not. Null only when nothing was ever stored for the URI.
     */
    public function findEntry(string $uri): ?CachedPlatformProfile;

    /**
     * @param int $expiresAt unix timestamp after which the entry is stale
     * @param string|null $etag the platform's validator for this representation, if it sent one
     */
    public function saveEntry(string $uri, PlatformProfile $profile, int $expiresAt, ?string $etag = null): void;
}
