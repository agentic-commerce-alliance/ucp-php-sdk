<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Internal;

use Ucp\Sdk\Internal\Security\AgentDomainAllowList;

/**
 * Bundle-side facade over the shared agent-domain allow-list.
 *
 * The matching itself lives in `packages/core` because the platform-profile gate reads
 * the same configured list and cannot depend on this bundle. Keeping this class means the
 * embedded transport's call sites read as they did.
 *
 * @internal
 */
final class OriginMatcher
{
    /**
     * @param list<string> $allowedOrigins
     */
    public static function allows(string $origin, array $allowedOrigins, string $baseUri): bool
    {
        return AgentDomainAllowList::allowsOrigin($origin, $allowedOrigins, $baseUri);
    }

    public static function normalizeOrigin(string $origin): ?string
    {
        return AgentDomainAllowList::canonicalOrigin($origin);
    }

    public static function normalizeUriOrigin(string $uri): ?string
    {
        return AgentDomainAllowList::originOfUri($uri);
    }
}
