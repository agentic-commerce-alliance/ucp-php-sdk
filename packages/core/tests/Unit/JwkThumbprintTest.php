<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Internal\Security\JwkThumbprint;

final class JwkThumbprintTest extends TestCase
{
    /**
     * The worked example from RFC 7638 section 3.1, key and expected thumbprint both.
     *
     * This is the one place a canonical vector exists, so it is used instead of a fixture this
     * repository generated -- a self-made fixture would only prove the implementation agrees
     * with itself, which is exactly what a canonicalisation bug also does.
     */
    #[Test]
    public function itMatchesTheRfc7638WorkedExample(): void
    {
        $jwk = [
            'kty' => 'RSA',
            'n' => '0vx7agoebGcQSuuPiLJXZptN9nndrQmbXEps2aiAFbWhM78LhWx4cbbfAAt'
                . 'VT86zwu1RK7aPFFxuhDR1L6tSoc_BJECPebWKRXjBZCiFV4n3oknjhMstn6'
                . '4tZ_2W-5JsGY4Hc5n9yBXArwl93lqt7_RN5w6Cf0h4QyQ5v-65YGjQR0_FD'
                . 'W2QvzqY368QQMicAtaSqzs8KJZgnYb9c7d0zgdAZHzu6qMQvRL5hajrn1n9'
                . '1CbOpbISD08qNLyrdkt-bFTWhAI4vMQFh6WeZu0fM4lFd2NcRwr3XPksINH'
                . 'aQ-G_xBniIqbw0Ls1jF44-csFCur-kEgU8awapJzKnqDKgw',
            'e' => 'AQAB',
            'alg' => 'RS256',
            'kid' => '2011-04-29',
        ];

        self::assertSame(
            'NzbLsXh8uDCcd-6MNwXF4W_7noWXFZAfHkxZsRGC9Xs',
            (new JwkThumbprint())->compute($jwk),
        );
    }

    #[Test]
    public function membersThatAreNotRequiredDoNotChangeIt(): void
    {
        // The reason only required members take part: the same key described twice, once with
        // `alg` and once without, has to name itself the same way both times.
        $thumbprint = new JwkThumbprint();
        $base = ['kty' => 'EC', 'crv' => 'P-256', 'x' => 'abc', 'y' => 'def'];

        self::assertSame(
            $thumbprint->compute($base),
            $thumbprint->compute([...$base, 'alg' => 'ES256', 'use' => 'sig', 'kid' => 'anything']),
        );
    }

    #[Test]
    public function memberOrderInTheInputDoesNotChangeIt(): void
    {
        $thumbprint = new JwkThumbprint();

        self::assertSame(
            $thumbprint->compute(['kty' => 'EC', 'crv' => 'P-256', 'x' => 'abc', 'y' => 'def']),
            $thumbprint->compute(['y' => 'def', 'x' => 'abc', 'kty' => 'EC', 'crv' => 'P-256']),
        );
    }

    #[Test]
    public function differentKeysGetDifferentThumbprints(): void
    {
        $thumbprint = new JwkThumbprint();

        self::assertNotSame(
            $thumbprint->compute(['kty' => 'EC', 'crv' => 'P-256', 'x' => 'abc', 'y' => 'def']),
            $thumbprint->compute(['kty' => 'EC', 'crv' => 'P-256', 'x' => 'abc', 'y' => 'dee']),
        );
    }

    #[Test]
    public function anOkpKeyHasNoYAndStillHasAThumbprint(): void
    {
        $value = (new JwkThumbprint())->compute(['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => 'abc']);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $value);
    }

    #[Test]
    public function aMissingRequiredMemberIsRefused(): void
    {
        // Defaulting the absent member would give two different keys the same name.
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('missing the required member "y"');

        (new JwkThumbprint())->compute(['kty' => 'EC', 'crv' => 'P-256', 'x' => 'abc']);
    }

    #[Test]
    public function anUnknownKeyTypeIsRefused(): void
    {
        $this->expectException(SignatureException::class);

        (new JwkThumbprint())->compute(['kty' => 'Frobnicate', 'x' => 'abc']);
    }
}
