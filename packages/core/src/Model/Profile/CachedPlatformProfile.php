<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Profile;

/**
 * A platform profile as the cache holds it: the document plus the two facts needed to decide
 * whether to ask the platform again -- when it stops being fresh, and the validator to ask with.
 */
final class CachedPlatformProfile
{
    public function __construct(
        public readonly PlatformProfile $profile,
        public readonly int $expiresAt,
        public readonly ?string $etag = null,
    ) {
    }

    public function isFresh(?int $now = null): bool
    {
        return $this->expiresAt >= ($now ?? time());
    }
}
