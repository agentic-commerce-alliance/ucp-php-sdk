<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

use phpseclib3\Crypt\PublicKeyLoader;

/** @internal */
final class EcJwkPublicKeyConverter
{
    public static function toPem(?string $curve, ?string $x, ?string $y): ?string
    {
        if ($curve === null || $x === null || $y === null || $curve === '' || $x === '' || $y === '') {
            return null;
        }

        if (!in_array($curve, ['P-256', 'P-384'], true)) {
            return null;
        }

        try {
            $jwk = json_encode([
                'kty' => 'EC',
                'crv' => $curve,
                'x' => $x,
                'y' => $y,
            ], JSON_THROW_ON_ERROR);
            $pem = PublicKeyLoader::loadPublicKey($jwk)->toString('PKCS8');

            return openssl_pkey_get_public($pem) === false ? null : $pem;
        } catch (\Throwable) {
            return null;
        }
    }
}
