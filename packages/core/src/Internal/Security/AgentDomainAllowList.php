<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

/**
 * The one reading of `allowed_agent_domains`.
 *
 * There used to be two, and they were mutually exclusive. The platform-profile gate
 * matched entries as bare domains with subdomain matching (`$host === $entry ||
 * str_ends_with($host, '.' . $entry)`), so `https://agent.example` matched no host that
 * ever existed. The embedded transport's CORS check normalised entries as full origins
 * and dropped anything without a scheme, so `agent.example` produced an empty allow-list.
 * Whichever form a merchant wrote, one of the two gates refused everything -- and both
 * fail closed, which is why it read as "the feature does not work" rather than as a bug.
 *
 * Unified on domains, because that is what the setting is called and what the older of
 * the two consumers already did. An entry may still be written as a full origin, since
 * configurations exist in that form; its scheme and port are then honoured as a
 * narrowing rather than discarded.
 *
 * @internal
 */
final class AgentDomainAllowList
{
    /**
     * A bare domain requires https, because an allow-list entry that permits plaintext
     * framing is a downgrade nobody asked for. Loopback is the exception, and not a new
     * one -- DefaultHttpRequestContextFactory already carves out exactly these hosts for
     * local development.
     */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /**
     * Does a host fall under the allow-list, by exact match or as a subdomain?
     *
     * Used for the platform-profile gate, where the question is about a host rather than
     * an origin: the scheme of a profile URI is governed separately.
     *
     * @param list<string> $entries
     */
    public static function matchesHost(string $host, array $entries): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return false;
        }

        foreach (self::rules($entries) as $rule) {
            if (self::hostFallsUnder($host, $rule['host'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * May a browser at this origin talk to a merchant published at this base URI?
     *
     * The merchant's own origin is always allowed and need not be listed: the embedded
     * pages are served from it, and a same-origin frame is not a cross-origin decision.
     *
     * @param list<string> $entries
     */
    public static function allowsOrigin(string $origin, array $entries, string $baseUri): bool
    {
        $parsed = self::parseOrigin($origin);
        if ($parsed === null) {
            return false;
        }

        $base = self::parseUriOrigin($baseUri);
        if ($base !== null && self::sameOrigin($parsed, $base)) {
            return true;
        }

        foreach (self::rules($entries) as $rule) {
            if (self::ruleAdmits($parsed, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this entry something this allow-list can act on at all?
     *
     * Exposed so a misconfiguration can be refused where an operator will see it rather
     * than skipped at request time. Silently dropping an unusable entry is what made the
     * original defect invisible.
     */
    public static function isUsableEntry(string $entry): bool
    {
        return self::toRule($entry) !== null;
    }

    /**
     * The canonical origin of a URI, or null when there is not one.
     *
     * A base URI legitimately carries a path -- `https://merchant.example/shop` is a
     * reasonable value -- so this is deliberately more tolerant than an `Origin` header
     * may be.
     */
    public static function originOfUri(string $uri): ?string
    {
        $parsed = self::parseUriOrigin($uri);

        return $parsed === null ? null : self::format($parsed);
    }

    /**
     * The canonical form of an `Origin` header value, or null when it is not one.
     *
     * An origin is a scheme, a host and optionally a port -- nothing else. A value
     * carrying a path, query, fragment or userinfo is refused rather than trimmed down,
     * because reducing `https://agent.example@attacker.example` to its host would make it
     * compare equal to `https://agent.example`.
     */
    public static function canonicalOrigin(string $origin): ?string
    {
        $parsed = self::parseOrigin($origin);

        return $parsed === null ? null : self::format($parsed);
    }

    /**
     * @param list<string> $entries
     *
     * @return list<array{host: string, scheme: ?string, port: ?int}>
     */
    private static function rules(array $entries): array
    {
        $rules = [];
        foreach ($entries as $entry) {
            $rule = self::toRule($entry);
            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * @return array{host: string, scheme: ?string, port: ?int}|null
     */
    private static function toRule(string $entry): ?array
    {
        $entry = strtolower(trim($entry));
        if ($entry === '') {
            return null;
        }

        if (str_contains($entry, '://')) {
            $parsed = self::parseOrigin($entry);

            return $parsed === null ? null : $parsed;
        }

        // An address literal, which `[::1]` also spells. Matched exactly rather than as a
        // domain: an address has no subdomains, and `str_ends_with($host, '.127.0.0.1')`
        // describes nothing. The merchant example configures the loopback set this way,
        // because `localhost`, `127.0.0.1` and `::1` are one host and which of them appears
        // depends on how the app was started rather than on who it should trust.
        $unbracketed = self::unbracket($entry);
        if (filter_var($unbracketed, FILTER_VALIDATE_IP) !== false) {
            return ['host' => $unbracketed, 'scheme' => null, 'port' => null];
        }

        // A bare domain. Anything else that would need parsing -- a path, a port,
        // credentials -- has to be written as a full origin instead, so that an entry never
        // means one thing here and another to parse_url().
        if (preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*$/', $entry) !== 1) {
            return null;
        }

        return ['host' => $entry, 'scheme' => null, 'port' => null];
    }

    /**
     * @param array{host: string, scheme: ?string, port: ?int} $origin
     * @param array{host: string, scheme: ?string, port: ?int} $rule
     */
    private static function ruleAdmits(array $origin, array $rule): bool
    {
        if ($rule['scheme'] !== null) {
            // Written as an origin, so compared as one: exactly this scheme, host and
            // port. Widening it to subdomains would loosen an allow-list somebody chose
            // to pin, which is the opposite of what writing it out that way asks for.
            return self::sameOrigin($origin, $rule);
        }

        if (! self::hostFallsUnder($origin['host'], $rule['host'])) {
            return false;
        }

        // A port is part of an origin, so two ports are two security principals -- on a
        // developer's machine `localhost:8081` is very likely somebody else's
        // application. A bare domain therefore covers only the scheme's default port; an
        // agent on another port is written out as a full origin, which is what the
        // container-build error message says.
        if ($origin['port'] !== null) {
            return false;
        }

        return $origin['scheme'] === 'https'
            || in_array($origin['host'], self::LOOPBACK_HOSTS, true);
    }

    private static function hostFallsUnder(string $host, string $allowedHost): bool
    {
        if ($host === $allowedHost) {
            return true;
        }

        // Only names have subdomains. Suffix-matching an address would be meaningless at
        // best and, for a v4 literal, would admit any host ending in those digits.
        if (filter_var($allowedHost, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return str_ends_with($host, '.' . $allowedHost);
    }

    /**
     * @param array{host: string, scheme: ?string, port: ?int} $a
     * @param array{host: string, scheme: ?string, port: ?int} $b
     */
    private static function sameOrigin(array $a, array $b): bool
    {
        return $a['scheme'] === $b['scheme'] && $a['host'] === $b['host'] && $a['port'] === $b['port'];
    }

    /**
     * @return array{host: string, scheme: ?string, port: ?int}|null
     */
    private static function parseOrigin(string $origin): ?array
    {
        $parts = parse_url(trim($origin));
        if (! is_array($parts)) {
            return null;
        }

        if (
            isset($parts['path'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        return self::fromParts($parts);
    }

    /**
     * @return array{host: string, scheme: ?string, port: ?int}|null
     */
    private static function parseUriOrigin(string $uri): ?array
    {
        $parts = parse_url(trim($uri));

        return is_array($parts) ? self::fromParts($parts) : null;
    }

    /**
     * @param array<string, mixed> $parts
     *
     * @return array{host: string, scheme: ?string, port: ?int}|null
     */
    private static function fromParts(array $parts): ?array
    {
        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? strtolower($parts['scheme']) : null;
        $host = isset($parts['host']) && is_string($parts['host']) ? self::unbracket(strtolower($parts['host'])) : null;

        if (($scheme !== 'http' && $scheme !== 'https') || $host === null || $host === '') {
            return null;
        }

        $port = isset($parts['port']) && is_int($parts['port']) ? $parts['port'] : null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            // A browser omits the default port, so keeping it would make an allow-list
            // entry written with it fail to match the origin the browser actually sends.
            $port = null;
        }

        return ['host' => $host, 'scheme' => $scheme, 'port' => $port];
    }

    /**
     * @param array{host: string, scheme: ?string, port: ?int} $origin
     */
    private static function format(array $origin): string
    {
        $host = $origin['host'];
        if (str_contains($host, ':')) {
            // An IPv6 host has to go back in brackets, or the port that may follow it cannot
            // be told from the address's own colons.
            $host = '[' . $host . ']';
        }

        return $origin['scheme'] . '://' . $host . ($origin['port'] === null ? '' : ':' . $origin['port']);
    }

    private static function unbracket(string $host): string
    {
        return str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;
    }
}
