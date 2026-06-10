<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Model\Security\PublicSigningKey;
use Ucp\Sdk\Service\SigningKeyManagerInterface;

/** @internal */
final class DefaultSigningKeyManager implements SigningKeyManagerInterface
{
    public function generate(string $kid, string $algorithm = 'ES256'): ManagedSigningKey
    {
        $curveName = $algorithm === 'ES384' ? 'secp384r1' : 'prime256v1';
        $curve = $algorithm === 'ES384' ? 'P-384' : 'P-256';
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
            $algorithm,
            'EC',
            'sig',
            'active',
            $curve,
            gmdate('c'),
        );
    }

    public function toPublicKey(ManagedSigningKey $key): PublicSigningKey
    {
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
            $x = isset($details['ec']['x']) && is_string($details['ec']['x']) ? rtrim(strtr(base64_encode($details['ec']['x']), '+/', '-_'), '=') : null;
            $y = isset($details['ec']['y']) && is_string($details['ec']['y']) ? rtrim(strtr(base64_encode($details['ec']['y']), '+/', '-_'), '=') : null;
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

    /**
     * @param array<string, string> $jwk
     */
    public function publicKeyFromJwk(array $jwk): PublicSigningKey
    {
        return PublicSigningKey::fromJwk($jwk);
    }
}
