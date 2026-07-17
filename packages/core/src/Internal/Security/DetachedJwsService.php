<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Model\Security\PublicSigningKey;
use Ucp\Sdk\Service\DeterministicJsonInterface;

/**
 * ES256 detached JWS (RFC 7515 Appendix F) over the base64url-encoded JCS
 * canonicalization of checkout payloads. The top-level `ap2` member is removed
 * before canonicalization so the signature can be embedded into the payload it
 * covers.
 *
 * @internal
 */
final class DetachedJwsService
{
    public function __construct(
        private readonly DeterministicJsonInterface $canonicalizer,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function signWithoutAp2(array $payload, ManagedSigningKey $key): string
    {
        $protected = Base64Url::encode(json_encode([
            'alg' => 'ES256',
            'kid' => $key->kid,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $signingInput = $protected . '.' . Base64Url::encode($this->canonicalPayload($payload));

        try {
            $privateKey = PublicKeyLoader::loadPrivateKey($key->privateKeyPem);
        } catch (\Throwable) {
            throw new SignatureException('Unable to load private signing key.');
        }

        if (! $privateKey instanceof EC\PrivateKey) {
            throw new SignatureException('Unable to load private signing key.');
        }

        $signature = $privateKey->withSignatureFormat('IEEE')->withHash('sha256')->sign($signingInput);

        return $protected . '..' . Base64Url::encode($signature);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<PublicSigningKey> $keys
     */
    public function verifyWithoutAp2(array $payload, string $jws, array $keys): bool
    {
        $segments = explode('.', $jws);
        if (count($segments) !== 3 || $segments[1] !== '') {
            return false;
        }

        [$protected, , $encodedSignature] = $segments;

        $header = json_decode(Base64Url::decode($protected) ?? '', true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'ES256') {
            return false;
        }

        // RFC 7797 unencoded payloads and other crit extensions are not supported.
        if (array_key_exists('b64', $header) || array_key_exists('crit', $header)) {
            return false;
        }

        $kid = $header['kid'] ?? null;
        if (! is_string($kid)) {
            return false;
        }

        $publicKeyPem = null;
        foreach ($keys as $key) {
            if ($key->kid === $kid && $key->publicKeyPem !== null) {
                $publicKeyPem = $key->publicKeyPem;
                break;
            }
        }

        if ($publicKeyPem === null) {
            return false;
        }

        try {
            $publicKey = PublicKeyLoader::loadPublicKey($publicKeyPem);
        } catch (\Throwable) {
            return false;
        }

        if (! $publicKey instanceof EC\PublicKey) {
            return false;
        }

        $rawSignature = Base64Url::decode($encodedSignature);
        if ($rawSignature === null || strlen($rawSignature) !== 64) {
            return false;
        }

        $signingInput = $protected . '.' . Base64Url::encode($this->canonicalPayload($payload));

        return $publicKey->withSignatureFormat('IEEE')->withHash('sha256')->verify($signingInput, $rawSignature);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function canonicalPayload(array $payload): string
    {
        unset($payload['ap2']);

        return $this->canonicalizer->canonicalize($payload);
    }
}
