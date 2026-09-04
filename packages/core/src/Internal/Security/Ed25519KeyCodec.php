<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

use Ucp\Sdk\Exception\SignatureException;

/**
 * Moves Ed25519 keys between sodium's raw bytes and the PEM the SDK stores.
 *
 * libsodium deals in raw 32-byte keys; everything around it here -- `ManagedSigningKey`, the key
 * repositories, the console commands that print a key -- deals in PEM. Storing raw base64 in a
 * field called `publicKeyPem` would make the type a lie and make the value unreadable by every
 * other tool an operator has.
 *
 * The DER for Ed25519 is fixed-shape, which is what makes this a prefix rather than an encoder:
 * RFC 8410 gives one AlgorithmIdentifier with no parameters, and the key is always 32 bytes. So
 * both forms are a constant header followed by the key, and parsing is the same check in reverse.
 *
 * @internal
 */
final class Ed25519KeyCodec
{
    private const PUBLIC_KEY_BYTES = 32;
    private const SECRET_KEY_BYTES = 64;
    private const SEED_BYTES = 32;

    /** SubjectPublicKeyInfo header: SEQUENCE, AlgorithmIdentifier(1.3.101.112), BIT STRING. */
    private const SPKI_PREFIX = "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00";

    /** PKCS#8 PrivateKeyInfo header: version 0, same AlgorithmIdentifier, OCTET STRING(seed). */
    private const PKCS8_PREFIX = "\x30\x2e\x02\x01\x00\x30\x05\x06\x03\x2b\x65\x70\x04\x22\x04\x20";

    public function publicKeyToPem(string $rawPublicKey): string
    {
        if (strlen($rawPublicKey) !== self::PUBLIC_KEY_BYTES) {
            throw new SignatureException('An Ed25519 public key is 32 bytes.');
        }

        return $this->pem('PUBLIC KEY', self::SPKI_PREFIX . $rawPublicKey);
    }

    /**
     * The PKCS#8 form carries the 32-byte seed, not sodium's 64-byte secret key.
     *
     * Sodium's secret key is the seed with the public key appended, and RFC 8410 stores only the
     * seed -- so this drops a copy of the public half rather than losing anything.
     */
    public function secretKeyToPem(string $rawSecretKey): string
    {
        if (strlen($rawSecretKey) !== self::SECRET_KEY_BYTES) {
            throw new SignatureException('An Ed25519 secret key is 64 bytes.');
        }

        return $this->pem('PRIVATE KEY', self::PKCS8_PREFIX . substr($rawSecretKey, 0, self::SEED_BYTES));
    }

    public function publicKeyFromPem(string $pem): string
    {
        return $this->extract($pem, self::SPKI_PREFIX, self::PUBLIC_KEY_BYTES, 'public');
    }

    /**
     * Returns sodium's 64-byte secret key, rebuilt from the stored seed.
     */
    public function secretKeyFromPem(string $pem): string
    {
        $seed = $this->extract($pem, self::PKCS8_PREFIX, self::SEED_BYTES, 'private');
        if ($seed === '') {
            throw new SignatureException('Not an Ed25519 private key.');
        }

        return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));
    }

    private function extract(string $pem, string $prefix, int $length, string $kind): string
    {
        $der = $this->der($pem);

        if (! str_starts_with($der, $prefix) || strlen($der) !== strlen($prefix) + $length) {
            throw new SignatureException(sprintf('Not an Ed25519 %s key.', $kind));
        }

        return substr($der, strlen($prefix));
    }

    private function der(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END)[^-]+-----|\s+/', '', $pem);
        $der = base64_decode((string) $body, true);

        if ($der === false) {
            throw new SignatureException('Key is not valid PEM.');
        }

        return $der;
    }

    private function pem(string $label, string $der): string
    {
        return sprintf(
            "-----BEGIN %s-----\n%s-----END %s-----\n",
            $label,
            chunk_split(base64_encode($der), 64, "\n"),
            $label,
        );
    }
}
