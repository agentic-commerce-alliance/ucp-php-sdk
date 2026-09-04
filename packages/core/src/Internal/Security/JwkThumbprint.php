<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

use Ucp\Sdk\Exception\SignatureException;

/**
 * RFC 7638 JWK thumbprints.
 *
 * A thumbprint names a key by what it *is* rather than by what someone called it. An operator's
 * `kid` is a label -- two businesses both using `"default"` is not a collision anyone detects --
 * whereas a thumbprint is derived from the key material, so it is the same string wherever the
 * key appears and different for every other key.
 *
 * The construction is unusually strict, and each rule is load-bearing. Only the members RFC 7638
 * declares *required* for the key type take part, so a key is not given two different
 * thumbprints because one copy carried `alg` or `use`. They are ordered lexicographically and
 * serialised with no whitespace, because the hash is over bytes and any freedom in the encoding
 * would make the identifier depend on who encoded it.
 *
 * @internal
 */
final class JwkThumbprint
{
    /**
     * The required members per key type, already in lexicographic order.
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_MEMBERS = [
        'EC' => ['crv', 'kty', 'x', 'y'],
        'OKP' => ['crv', 'kty', 'x'],
        'RSA' => ['e', 'kty', 'n'],
        'oct' => ['k', 'kty'],
    ];

    /**
     * @param array<string, mixed> $jwk
     */
    public function compute(array $jwk): string
    {
        $kty = $jwk['kty'] ?? null;
        if (! is_string($kty) || ! isset(self::REQUIRED_MEMBERS[$kty])) {
            throw new SignatureException(sprintf(
                'Cannot compute a thumbprint for key type "%s".',
                is_string($kty) ? $kty : gettype($kty),
            ));
        }

        $canonical = [];
        foreach (self::REQUIRED_MEMBERS[$kty] as $member) {
            $value = $jwk[$member] ?? null;
            if (! is_string($value) || $value === '') {
                throw new SignatureException(sprintf('JWK is missing the required member "%s".', $member));
            }

            $canonical[$member] = $value;
        }

        // JSON_UNESCAPED_SLASHES and JSON_UNESCAPED_UNICODE are deliberate: RFC 7638 hashes the
        // JSON as required by RFC 7159, and escaping a character that need not be escaped would
        // change the bytes without changing the key.
        $json = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return self::base64Url(hash('sha256', $json, true));
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
