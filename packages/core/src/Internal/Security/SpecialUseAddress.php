<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

/**
 * The special-use IP ranges a profile fetch must never reach (RFC 6890 and successors).
 *
 * Listed explicitly rather than taken from PHP's `FILTER_FLAG_NO_PRIV_RANGE` and
 * `FILTER_FLAG_NO_RES_RANGE`: what those flags cover changes between PHP versions, and on the
 * versions this SDK supports they admit shared address space (`100.64.0.0/10`), the IETF
 * protocol block (`192.0.0.0/24`), benchmarking (`198.18.0.0/15`), site-local IPv6 and every
 * IPv6 form that embeds an IPv4 address. An IPv6 address that embeds an IPv4 one -- mapped,
 * compatible, NAT64 or 6to4 -- is judged by the address it embeds, because that is where the
 * connection ends up.
 *
 * @internal
 */
final class SpecialUseAddress
{
    /** @var list<string> */
    private const IPV4_RANGES = [
        '0.0.0.0/8',          // "this" network
        '10.0.0.0/8',         // private
        '100.64.0.0/10',      // shared address space (CGNAT)
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local, including cloud metadata
        '172.16.0.0/12',      // private
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // documentation (TEST-NET-1)
        '192.88.99.0/24',     // 6to4 relay anycast (deprecated)
        '192.168.0.0/16',     // private
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // documentation (TEST-NET-2)
        '203.0.113.0/24',     // documentation (TEST-NET-3)
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved, including limited broadcast
    ];

    /** @var list<string> */
    private const IPV6_RANGES = [
        '::/128',             // unspecified
        '::1/128',            // loopback
        '100::/64',           // discard-only
        '2001::/23',          // IETF protocol assignments, including ORCHID and TEREDO
        '2001:db8::/32',      // documentation
        '3fff::/20',          // documentation (RFC 9637)
        '5f00::/16',          // SRv6 segment identifiers (RFC 9602)
        'fc00::/7',           // unique local
        'fe80::/10',          // link-local
        'fec0::/10',          // site-local (deprecated)
        'ff00::/8',           // multicast
        '64:ff9b:1::/48',     // NAT64 local-use: the IPv4 address sits at a prefix-dependent offset, so the whole block is refused
        '::ffff:0:0:0/96',    // IPv4-translatable (SIIT)
    ];

    public static function isSpecialUse(string $ip): bool
    {
        $embedded = self::embeddedIpv4($ip);
        if ($embedded !== null) {
            return self::inAnyRange($embedded, self::IPV4_RANGES);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::inAnyRange($ip, self::IPV4_RANGES);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::inAnyRange($ip, self::IPV6_RANGES);
        }

        // Not an address at all: refused, as every unparsable input is.
        return true;
    }

    /**
     * IPv6 prefixes (hex of the packed address) that carry an IPv4 address, with the byte
     * offset of that address: IPv4-mapped (`::ffff:a.b.c.d`), IPv4-compatible (`::a.b.c.d`),
     * the NAT64 well-known prefix (`64:ff9b::/96`), and 6to4 (`2002::/16`). The NAT64 local-use
     * block `64:ff9b:1::/48` embeds the address at an offset that depends on the prefix length
     * (RFC 6052), so it is refused as a whole range instead of decoded. The compatible form also
     * matches `::` and `::1`; their embedded `0.0.0.0` and `0.0.0.1` fall into `0.0.0.0/8`.
     *
     * A list of pairs rather than a map: an all-digit key such as '2002' would become an int.
     *
     * @var list<array{string, int}>
     */
    private const EMBEDDING_PREFIXES = [
        ['00000000000000000000ffff', 12],
        ['000000000000000000000000', 12],
        ['0064ff9b0000000000000000', 12],
        ['2002', 2],
    ];

    /**
     * The IPv4 address an IPv6 address carries, for the forms that route to it.
     */
    private static function embeddedIpv4(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        $hex = bin2hex($packed);
        foreach (self::EMBEDDING_PREFIXES as [$prefix, $offset]) {
            if (str_starts_with($hex, $prefix)) {
                return inet_ntop(substr($packed, $offset, 4)) ?: null;
            }
        }

        return null;
    }

    /**
     * @param list<string> $ranges
     */
    private static function inAnyRange(string $ip, array $ranges): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }

        foreach ($ranges as $range) {
            [$network, $bits] = explode('/', $range, 2);
            $networkPacked = inet_pton($network);
            if ($networkPacked === false || strlen($networkPacked) !== strlen($packed)) {
                continue;
            }
            if (self::prefixMatches($packed, $networkPacked, (int) $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function prefixMatches(string $a, string $b, int $bits): bool
    {
        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && substr($a, 0, $fullBytes) !== substr($b, 0, $fullBytes)) {
            return false;
        }

        $remaining = $bits % 8;
        if ($remaining === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($a[$fullBytes]) & $mask) === (ord($b[$fullBytes]) & $mask);
    }
}
