<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

use Ucp\Sdk\Enum\SignatureAlgorithm;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Model\Security\PublicSigningKey;
use Ucp\Sdk\Service\SigningKeyManagerInterface;

/** @internal */
final class DefaultSigningKeyManager implements SigningKeyManagerInterface
{
    public function __construct(
        private readonly Ed25519KeyCodec $ed25519Codec = new Ed25519KeyCodec(),
    ) {
    }

    public function generate(string $kid, string $algorithm = 'ES256'): ManagedSigningKey
    {
        // Was `$algorithm === 'ES384' ? ... : ...`, so anything unrecognised silently produced a
        // P-256 key labelled with whatever was asked for -- `generate($kid, 'HS256')` returned a
        // key that then failed at signing time, or worse published a JWK whose `alg` and `crv`
        // disagreed. Resolving through the enum rejects it here instead.
        $resolved = SignatureAlgorithm::fromIdentifier($algorithm);

        // Ed25519 is not an openssl key type here: PHP does not define OPENSSL_KEYTYPE_ED25519,
        // so the extension cannot generate one at all. libsodium can, and the codec puts the
        // result into the PEM this SDK stores -- which openssl then reads back happily.
        if ($resolved === SignatureAlgorithm::Ed25519) {
            return $this->generateEd25519($kid, $resolved);
        }

        $curve = $resolved->curve();
        $curveName = match ($resolved) {
            SignatureAlgorithm::Es256 => 'prime256v1',
            // Ed25519 returned above; openssl cannot generate it at all.
            default => 'secp384r1',
        };
        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => $curveName,
        ]);

        if ($resource === false) {
            throw new SignatureException('Unable to generate signing key.');
        }

        openssl_pkey_export($resource, $privateKey);
        $details = openssl_pkey_get_details($resource);

        if (!is_array($details) || !isset($details['key']) || !is_string($details['key'])) {
            throw new SignatureException('Unable to extract public key details.');
        }

        return new ManagedSigningKey(
            $kid,
            $details['key'],
            $privateKey,
            $resolved->value,
            'EC',
            'sig',
            'active',
            $curve,
            gmdate('c'),
        );
    }

    private function generateEd25519(string $kid, SignatureAlgorithm $algorithm): ManagedSigningKey
    {
        $pair = sodium_crypto_sign_keypair();

        return new ManagedSigningKey(
            $kid,
            $this->ed25519Codec->publicKeyToPem(sodium_crypto_sign_publickey($pair)),
            $this->ed25519Codec->secretKeyToPem(sodium_crypto_sign_secretkey($pair)),
            $algorithm->value,
            $algorithm->keyType(),
            'sig',
            'active',
            $algorithm->curve(),
            gmdate('c'),
        );
    }

    public function toPublicKey(ManagedSigningKey $key): PublicSigningKey
    {
        // OKP keys carry the public key whole in `x` and have no `y` at all (RFC 8037), so the
        // EC coordinate path below would produce a JWK missing the only member that matters.
        if (SignatureAlgorithm::tryFromIdentifier($key->algorithm) === SignatureAlgorithm::Ed25519) {
            return new PublicSigningKey(
                $key->kid,
                $key->algorithm,
                'OKP',
                $key->use,
                SignatureAlgorithm::Ed25519->curve(),
                self::base64Url($this->ed25519Codec->publicKeyFromPem($key->publicKeyPem)),
                null,
                $key->publicKeyPem,
            );
        }

        $resource = openssl_pkey_get_public($key->publicKeyPem);
        $details = $resource !== false ? openssl_pkey_get_details($resource) : false;
        $x = null;
        $y = null;
        $curve = $key->curve;

        if (is_array($details) && isset($details['ec']) && is_array($details['ec'])) {
            $curveName = $details['ec']['curve_name'] ?? null;
            $curve = match ($curveName) {
                'prime256v1' => 'P-256',
                'secp384r1' => 'P-384',
                default => $curve,
            };
            $x = isset($details['ec']['x']) && is_string($details['ec']['x']) ? EcPublicKeyPem::encodeCoordinate($details['ec']['x'], $curve) : null;
            $y = isset($details['ec']['y']) && is_string($details['ec']['y']) ? EcPublicKeyPem::encodeCoordinate($details['ec']['y'], $curve) : null;
        }

        return new PublicSigningKey(
            $key->kid,
            $key->algorithm,
            $key->keyType,
            $key->use,
            $curve,
            $x,
            $y,
            $key->publicKeyPem,
        );
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @param array<string, string> $jwk
     */
    public function publicKeyFromJwk(array $jwk): PublicSigningKey
    {
        return PublicSigningKey::fromJwk($jwk);
    }
}
