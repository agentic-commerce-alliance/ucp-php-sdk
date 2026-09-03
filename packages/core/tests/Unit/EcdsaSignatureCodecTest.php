<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Internal\Security\EcdsaSignatureCodec;

/**
 * DER is signed and minimal; fixed-width r||s is neither. Those two differences are the bug.
 *
 * The DER fixtures here are assembled byte by byte from the ASN.1 rules rather than produced by
 * openssl, so they are an independent statement of what the input looks like. openssl is then
 * used as a second, independent encoder: whatever it emits must survive a round trip unchanged,
 * and whatever this codec produces must be something openssl accepts.
 */
final class EcdsaSignatureCodecTest extends TestCase
{
    /**
     * @param non-empty-string $r
     * @param non-empty-string $s
     */
    #[Test]
    #[DataProvider('coordinatePairs')]
    public function itRoundTripsBetweenDerAndFixedWidth(string $r, string $s): void
    {
        $codec = new EcdsaSignatureCodec();
        $raw = str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
        $der = self::der($r, $s);

        self::assertSame(64, strlen($codec->derToRaw($der, 32)), 'fixed-width output is always 2x the coordinate width');
        self::assertSame(bin2hex($raw), bin2hex($codec->derToRaw($der, 32)));
        self::assertSame(bin2hex($der), bin2hex($codec->rawToDer($raw, 32)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function coordinatePairs(): iterable
    {
        $high = str_repeat("\xff", 32);
        $typical = hex2bin(str_repeat('7f', 32));
        self::assertIsString($typical);

        // DER prepends 0x00 when the top bit is set. Carrying that byte into the raw form
        // yields 33 bytes and shifts everything; this is the first classic mistake.
        yield 'both coordinates have the top bit set' => [$high, $high];
        yield 'r has the top bit set, s does not' => [$high, $typical];

        // DER stores minimally, so a coordinate that happens to begin with a zero byte arrives
        // short and must be left-padded. Writing it short is the second classic mistake, and it
        // happens to roughly one coordinate in 256.
        yield 'r is one byte short' => [str_repeat("\x11", 31), $typical];
        yield 's is two bytes short' => [$typical, str_repeat("\x22", 30)];
        yield 'both are short' => [str_repeat("\x01", 31), str_repeat("\x02", 29)];
        yield 'r is a single byte' => ["\x01", $typical];
        yield 'both are a single byte' => ["\x01", "\x02"];
        yield 'typical' => [$typical, $typical];
    }

    /**
     * openssl as the second encoder: whatever it emits must survive the round trip byte for
     * byte, and must always convert to exactly 64 bytes.
     *
     * Every assertion here holds for any signature openssl happens to produce. An earlier
     * version also asserted that the loop had encountered at least one short coordinate, which
     * made the test fail about 4% of the time -- 800 draws at roughly 1 in 256 each miss
     * entirely more often than is tolerable. The short-coordinate case is covered
     * deterministically by the hand-built fixtures above instead, which is where it belongs.
     */
    #[Test]
    public function itAgreesWithOpensslAcrossManySignatures(): void
    {
        $codec = new EcdsaSignatureCodec();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);

        for ($i = 0; $i < 200; ++$i) {
            $der = '';
            self::assertTrue(openssl_sign('message ' . $i, $der, $key, OPENSSL_ALGO_SHA256));

            $raw = $codec->derToRaw($der, 32);
            self::assertSame(64, strlen($raw), 'openssl DER converted to a non-64-byte signature');
            self::assertSame(bin2hex($der), bin2hex($codec->rawToDer($raw, 32)), 'round trip changed the DER');
        }
    }

    #[Test]
    public function itProducesDerThatOpensslAccepts(): void
    {
        $codec = new EcdsaSignatureCodec();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        $der = '';
        self::assertTrue(openssl_sign('payload', $der, $key, OPENSSL_ALGO_SHA256));

        $reencoded = $codec->rawToDer($codec->derToRaw($der, 32), 32);

        self::assertSame(1, openssl_verify('payload', $reencoded, $details['key'], OPENSSL_ALGO_SHA256));
    }

    #[Test]
    public function itHandlesP384Widths(): void
    {
        $codec = new EcdsaSignatureCodec();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
        self::assertNotFalse($key);

        $der = '';
        self::assertTrue(openssl_sign('payload', $der, $key, OPENSSL_ALGO_SHA384));

        $raw = $codec->derToRaw($der, 48);

        self::assertSame(96, strlen($raw));
        self::assertSame(bin2hex($der), bin2hex($codec->rawToDer($raw, 48)));
    }

    #[Test]
    #[DataProvider('malformedDer')]
    public function itRefusesMalformedDer(string $der, string $expectedMessage): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage($expectedMessage);

        (new EcdsaSignatureCodec())->derToRaw($der, 32);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedDer(): iterable
    {
        yield 'empty' => ['', 'DER signature is not a sequence.'];
        yield 'not a sequence' => ["\x31\x06\x02\x01\x01\x02\x01\x02", 'DER signature is not a sequence.'];
        yield 'declared length disagrees' => ["\x30\x07\x02\x01\x01\x02\x01\x02", 'DER signature length does not match its contents.'];
        yield 'second component is not an integer' => ["\x30\x06\x02\x01\x01\x03\x01\x02", 'DER component is not a integer.'];
        // The sequence length here is honest; it is the integer inside that overruns it.
        yield 'truncated integer' => ["\x30\x04\x02\x05\x01\x02", 'DER signature integer is truncated.'];
        yield 'coordinate wider than the curve' => [self::der(str_repeat("\x11", 33), "\x01"), 'wider than the 32-byte curve coordinate'];
    }

    #[Test]
    public function itRefusesAFixedWidthSignatureOfTheWrongLength(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Expected a 64-byte fixed-width ECDSA signature, got 63 bytes.');

        (new EcdsaSignatureCodec())->rawToDer(str_repeat("\x01", 63), 32);
    }

    /**
     * Builds SEQUENCE { INTEGER r, INTEGER s } by hand, applying the ASN.1 sign rule.
     */
    private static function der(string $r, string $s): string
    {
        $body = self::integer($r) . self::integer($s);

        return "\x30" . chr(strlen($body)) . $body;
    }

    private static function integer(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '' || (ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }

        return "\x02" . chr(strlen($value)) . $value;
    }
}
