<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Internal\Security\EcPublicKeyPem;

/**
 * PublicSigningKey flattens every failure in here into one ValidationException with one message, so
 * the helper's own contract is asserted here rather than through its caller.
 */
final class EcPublicKeyPemTest extends TestCase
{
    #[DataProvider('supportedCurves')]
    #[Test]
    public function itAssemblesTheSamePemOpensslProducesForTheSameKey(string $curveName, string $curve): void
    {
        [$pem, $x, $y] = self::generateKey($curveName);

        self::assertSame($pem, EcPublicKeyPem::fromCoordinates($curve, $x, $y));
    }

    #[DataProvider('supportedCurves')]
    #[Test]
    public function itLeavesACanonicalPemUnchanged(string $curveName, string $curve): void
    {
        [$pem] = self::generateKey($curveName);

        self::assertSame($pem, EcPublicKeyPem::normalize($pem));
    }

    #[Test]
    public function itRejectsKeyMaterialOpensslCannotRead(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Public key material is not a readable PEM public key.');

        EcPublicKeyPem::normalize('not-a-public-key');
    }

    #[Test]
    public function itRejectsCoordinatesThatAreNotBase64Url(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Public key coordinate is not a base64url value the curve can hold.');

        EcPublicKeyPem::fromCoordinates('P-256', 'not base64url!!', 'not base64url!!');
    }

    #[Test]
    public function itRejectsCoordinatesWiderThanTheCurve(): void
    {
        [, $x, $y] = self::generateKey('secp384r1');

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Public key coordinate is not a base64url value the curve can hold.');

        EcPublicKeyPem::fromCoordinates('P-256', $x, $y);
    }

    /**
     * openssl returns EC coordinates as minimal-form integers, so about one in 256 is a byte short
     * of the curve width -- and DefaultSigningKeyManager::toPublicKey() base64url-encodes exactly
     * what it is given. Requiring the full width here rejected the SDK's own JWKs at that rate,
     * which surfaced as an integration test failing roughly one run in a hundred. The vector below
     * is a real P-256 public key with a 31-byte y, kept as a fixture because generating one on
     * demand is a coin flip.
     */
    #[Test]
    public function itLeftPadsAMinimalFormCoordinateToTheCurveWidth(): void
    {
        $x = 'G2g_erNlzpRUSTVQUbeInbB5o5ipPruxPHKnwloQ9cQ';
        $y = 'UyIiffMWPWbLh3Dh5RjXtra4VBPEilBDhIygyyKsfQ';

        self::assertSame(31, strlen((string) base64_decode(strtr($y, '-_', '+/'), true)));

        $pem = EcPublicKeyPem::fromCoordinates('P-256', $x, $y);

        $resource = openssl_pkey_get_public($pem);
        self::assertNotFalse($resource);

        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);
        self::assertIsArray($details['ec']);
        self::assertSame($x, self::base64UrlEncode((string) $details['ec']['x']));
        self::assertSame($y, self::base64UrlEncode((string) $details['ec']['y']));
    }

    #[Test]
    public function itEncodesAShortCoordinateAtTheFullCurveWidth(): void
    {
        $minimalForm = (string) base64_decode('UyIiffMWPWbLh3Dh5RjXtra4VBPEilBDhIygyyKsfQ==', true);
        self::assertSame(31, strlen($minimalForm));

        self::assertSame(
            'AFMiIn3zFj1my4dw4eUY17a2uFQTxIpQQ4SMoMsirH0',
            EcPublicKeyPem::encodeCoordinate($minimalForm, 'P-256'),
        );
    }

    #[Test]
    public function itEncodesAFullWidthCoordinateUnchanged(): void
    {
        // Deliberately not a generated key: openssl returns minimal-form integers, so one in 256
        // of those is already a byte short and would be padded here -- making the assertion a
        // coin flip rather than a statement about full-width input.
        $coordinate = str_repeat("\x7f", 32);

        self::assertSame(
            self::base64UrlEncode($coordinate),
            EcPublicKeyPem::encodeCoordinate($coordinate, 'P-256'),
        );
    }

    #[Test]
    public function itDoesNotPadACoordinateForACurveItHasNoWidthFor(): void
    {
        self::assertSame('AQI', EcPublicKeyPem::encodeCoordinate("\x01\x02", 'P-521'));
        self::assertSame('AQI', EcPublicKeyPem::encodeCoordinate("\x01\x02", null));
    }

    #[Test]
    public function itRejectsAnEmptyCoordinate(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Public key coordinate is not a base64url value the curve can hold.');

        EcPublicKeyPem::fromCoordinates('P-256', '', '');
    }

    #[Test]
    public function itRejectsCoordinatesThatAreNotAPointOnTheCurve(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Public key material is not a readable PEM public key.');

        EcPublicKeyPem::fromCoordinates(
            'P-256',
            self::base64UrlEncode(str_repeat("\x11", 32)),
            self::base64UrlEncode(str_repeat("\x22", 32)),
        );
    }

    #[Test]
    public function itRejectsCurvesItHasNoPrefixFor(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Unsupported curve "P-521".');

        EcPublicKeyPem::fromCoordinates('P-521', 'x', 'y');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function supportedCurves(): iterable
    {
        yield 'P-256' => ['prime256v1', 'P-256'];
        yield 'P-384' => ['secp384r1', 'P-384'];
    }

    /**
     * @return array{string, string, string} the canonical PEM plus base64url x and y
     */
    private static function generateKey(string $curveName): array
    {
        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => $curveName,
        ]);
        self::assertNotFalse($resource);

        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);
        self::assertIsString($details['key']);
        self::assertIsArray($details['ec']);
        self::assertIsString($details['ec']['x']);
        self::assertIsString($details['ec']['y']);

        return [
            $details['key'],
            self::base64UrlEncode($details['ec']['x']),
            self::base64UrlEncode($details['ec']['y']),
        ];
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
