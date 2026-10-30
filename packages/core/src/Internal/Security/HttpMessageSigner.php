<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

use Ucp\Sdk\Enum\SignatureAlgorithm;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Model\Security\ManagedSigningKey;

/**
 * The cryptographic step of RFC 9421, shared by request and response signing: turns a
 * signature base into the signature bytes that go on the wire, and checks such bytes.
 *
 * The wire form is fixed-width `r||s` for ECDSA and the RFC 8032 bytes for Ed25519. Building
 * the base and choosing the covered components stay with the services.
 *
 * @internal
 */
final class HttpMessageSigner
{
    public function __construct(
        private readonly EcdsaSignatureCodec $signatureCodec = new EcdsaSignatureCodec(),
        private readonly Ed25519KeyCodec $ed25519Codec = new Ed25519KeyCodec(),
    ) {
    }

    /**
     * Accepts the fixed-width form the spec requires, and DER for one transition release.
     *
     * Releases up to 0.0.5 emitted DER here, so a peer still running one of those keeps working
     * across the upgrade. Dispatch is on length rather than on the leading 0x30 byte: a raw
     * signature starts with 0x30 once every 256 signatures, which would misread a conformant
     * signature as DER and reject it. A DER signature that happens to be exactly the raw length
     * needs both halves to encode two bytes short, which is around one in 2^48.
     */
    private function toDer(string $signature, SignatureAlgorithm $algorithm): string
    {
        if (strlen($signature) === $algorithm->coordinateBytes() * 2) {
            return $this->signatureCodec->rawToDer($signature, $algorithm->coordinateBytes());
        }

        return $signature;
    }

    /**
     * The signature as it goes on the wire.
     *
     * Ed25519 is not routed through openssl: PHP's extension cannot use these keys, and the
     * signature RFC 8032 defines is already the 64 bytes the wire wants -- putting it through
     * the ECDSA codec would corrupt it rather than reformat it.
     */
    public function sign(string $base, ManagedSigningKey $key, SignatureAlgorithm $algorithm, string $subject): string
    {
        if ($algorithm === SignatureAlgorithm::Ed25519) {
            $secretKey = $this->ed25519Codec->secretKeyFromPem($key->privateKeyPem);
            if ($secretKey === '') {
                throw new SignatureException('Ed25519 signing key is empty.');
            }

            return sodium_crypto_sign_detached($base, $secretKey);
        }

        $signature = '';
        if (! openssl_sign($base, $signature, $key->privateKeyPem, $this->opensslAlgorithm($algorithm))) {
            throw new SignatureException(sprintf('Unable to sign %s.', $subject));
        }

        // openssl emits DER; the wire format is fixed-width r||s.
        return $this->signatureCodec->derToRaw($signature, $algorithm->coordinateBytes());
    }

    public function verify(string $base, string $signature, string $publicKeyPem, SignatureAlgorithm $algorithm): int
    {
        if ($algorithm !== SignatureAlgorithm::Ed25519) {
            $result = openssl_verify($base, $this->toDer($signature, $algorithm), $publicKeyPem, $this->opensslAlgorithm($algorithm));

            // openssl reports an internal error as false, which is not a verdict on the
            // signature. -1 is what openssl_verify itself uses for that, and the caller already
            // treats anything other than 1 as unverified.
            return $result === false ? -1 : $result;
        }

        // A wrong-length signature is a failed verification, not an exception: a peer sending
        // one is not a configuration problem here, and throwing would turn a bad signature into
        // a different status code than a bad signature.
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return 0;
        }

        try {
            $publicKey = $this->ed25519Codec->publicKeyFromPem($publicKeyPem);
        } catch (SignatureException) {
            return 0;
        }

        if ($publicKey === '') {
            return 0;
        }

        return sodium_crypto_sign_verify_detached($signature, $base, $publicKey) ? 1 : 0;
    }

    private function opensslAlgorithm(SignatureAlgorithm $algorithm): int
    {
        return match ($algorithm) {
            SignatureAlgorithm::Es256 => OPENSSL_ALGO_SHA256,
            SignatureAlgorithm::Es384 => OPENSSL_ALGO_SHA384,
            SignatureAlgorithm::Ed25519 => throw new SignatureException('Ed25519 does not use an openssl digest algorithm.'),
        };
    }
}
