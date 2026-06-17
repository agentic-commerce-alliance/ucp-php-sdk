<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Internal;

final class OriginMatcher
{
    /**
     * @param list<string> $allowedOrigins
     */
    public static function allows(string $origin, array $allowedOrigins, string $baseUri): bool
    {
        $normalizedOrigin = self::normalizeOrigin($origin);
        if ($normalizedOrigin === null) {
            return false;
        }

        $normalizedAllowedOrigins = [];
        foreach ($allowedOrigins as $allowedOrigin) {
            $normalizedAllowedOrigin = self::normalizeOrigin($allowedOrigin);
            if ($normalizedAllowedOrigin !== null) {
                $normalizedAllowedOrigins[] = $normalizedAllowedOrigin;
            }
        }

        $baseOrigin = self::normalizeUriOrigin($baseUri);
        if ($baseOrigin !== null) {
            $normalizedAllowedOrigins[] = $baseOrigin;
        }

        return in_array($normalizedOrigin, array_unique($normalizedAllowedOrigins), true);
    }

    public static function normalizeOrigin(string $origin): ?string
    {
        $parts = parse_url($origin);
        if (! is_array($parts)) {
            return null;
        }

        if (isset($parts['path']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return self::normalizeParsedOrigin($parts);
    }

    public static function normalizeUriOrigin(string $uri): ?string
    {
        $parts = parse_url($uri);
        if (! is_array($parts)) {
            return null;
        }

        return self::normalizeParsedOrigin($parts);
    }

    /**
     * @param array{scheme?: string, host?: string, port?: int} $parts
     */
    private static function normalizeParsedOrigin(array $parts): ?string
    {
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : null;
        $host = isset($parts['host']) ? strtolower($parts['host']) : null;
        if (($scheme !== 'http' && $scheme !== 'https') || $host === null || $host === '') {
            return null;
        }

        $origin = $scheme . '://' . $host;
        $port = $parts['port'] ?? null;
        if ($port !== null && ! (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $origin .= ':' . $port;
        }

        return $origin;
    }
}
