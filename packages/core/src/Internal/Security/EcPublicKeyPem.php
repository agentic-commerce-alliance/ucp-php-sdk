<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

use Ucp\Sdk\Exception\SignatureException;

/**
 * Builds and normalizes SubjectPublicKeyInfo PEM blocks for the EC curves this SDK signs with.
 *
 * ext-openssl is a hard requirement of this package and already produces every key
 * DefaultSigningKeyManager hands out, so it is the formatter here too: normalize() round-trips
 * through openssl, which leaves exactly one PEM shape in the system. Before this existed the two
 * producers of PublicSigningKey::$publicKeyPem disagreed -- openssl emits LF with a trailing
 * newline, the phpseclib encoder this replaced emitted CRLF without one -- over identical DER.
 *
 * openssl cannot import raw JWK coordinates, so fromCoordinates() assembles the
 * SubjectPublicKeyInfo itself. For a fixed curve that is a constant prefix followed by the
 * uncompressed point (RFC 5480, section 2.2), so this is a lookup table rather than an ASN.1
 * encoder, and the result is handed straight back to openssl -- which is what rejects a pair of
 * coordinates that is not a point on the curve.
 *
 * @internal
 */
final class EcPublicKeyPem
{
    /**
     * Per curve: the DER for SEQUENCE { SEQUENCE { id-ecPublicKey, named curve }, BIT STRING },
     * up to and including the 0x04 uncompressed-point marker, and the byte length of one
     * coordinate. Written as bytes rather than hex so no decode can fail at runtime; the hex is
     * spelled out for review, and PublicSigningKeyTest pins both against openssl's own output.
     *
     *   P-256  3059301306072a8648ce3d020106082a8648ce3d03010703420004
     *   P-384  3076301006072a8648ce3d020106052b8104002203620004
     *
     * @var array<string, array{prefix: non-empty-string, coordinateLength: positive-int}>
     */
    private const CURVES = [
        'P-256' => [
            'prefix' => "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00\x04",
            'coordinateLength' => 32,
        ],
        'P-384' => [
            'prefix' => "\x30\x76\x30\x10\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x05\x2b\x81\x04\x00\x22\x03\x62\x00\x04",
            'coordinateLength' => 48,
        ],
    ];

    /**
     * Re-emits any openssl-readable public key in canonical SubjectPublicKeyInfo PEM form.
     */
    public static function normalize(string $pem): string
    {
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new SignatureException('Public key material is not a readable PEM public key.');
        }

        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || !isset($details['key']) || !is_string($details['key'])) {
            throw new SignatureException('Public key material could not be normalized.');
        }

        return $details['key'];
    }

    /**
     * Assembles a public key PEM from the base64url coordinates of an EC JWK.
     */
    public static function fromCoordinates(string $curve, string $x, string $y): string
    {
        $spec = self::CURVES[$curve] ?? throw new SignatureException(sprintf('Unsupported curve "%s".', $curve));

        $der = $spec['prefix']
            . self::coordinate($x, $spec['coordinateLength'])
            . self::coordinate($y, $spec['coordinateLength']);

        return self::normalize(
            "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n",
        );
    }

    /**
     * Encodes a raw coordinate as the base64url string a JWK carries.
     *
     * RFC 7518 section 6.2.1.2 requires the octet string to be the full width of a coordinate for
     * the curve, and openssl hands these over as minimal-form integers -- so the left-padding is
     * the point of this method, not a detail of it. An unrecognised curve is passed through
     * unpadded, because a width we do not know is not a width we may invent.
     */
    public static function encodeCoordinate(string $coordinate, ?string $curve): string
    {
        $length = self::CURVES[$curve ?? '']['coordinateLength'] ?? null;

        if ($length !== null && strlen($coordinate) < $length) {
            $coordinate = str_pad($coordinate, $length, "\x00", STR_PAD_LEFT);
        }

        return rtrim(strtr(base64_encode($coordinate), '+/', '-_'), '=');
    }

    /**
     * The uncompressed point needs each coordinate at its curve's full width, so a shorter one is
     * left-padded with zero bytes. That is not defensive: openssl returns these integers in
     * minimal form, so roughly one coordinate in 256 comes back a byte short -- which is what
     * `DefaultSigningKeyManager::toPublicKey()` then base64url-encodes into a JWK, and what this
     * has to read back. A value *wider* than the curve is a different key, not a padding
     * question, and is rejected.
     */
    private static function coordinate(string $value, int $length): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false || $decoded === '' || strlen($decoded) > $length) {
            throw new SignatureException('Public key coordinate is not a base64url value the curve can hold.');
        }

        return str_pad($decoded, $length, "\x00", STR_PAD_LEFT);
    }
}
