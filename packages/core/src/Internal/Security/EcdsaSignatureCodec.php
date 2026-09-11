<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

use Ucp\Sdk\Exception\SignatureException;

/**
 * Converts ECDSA signatures between DER and the fixed-width form RFC 9421 requires.
 *
 * `openssl_sign()` emits DER -- `SEQUENCE { INTEGER r, INTEGER s }`, around 70 to 72 bytes for
 * P-256, leading `0x30`. RFC 9421 section 3.3.1 requires `r || s`, each left-padded to the
 * curve's coordinate width: exactly 64 bytes for P-256 and 96 for P-384. The two are not
 * interchangeable, so every signature this SDK emitted was rejected by every conformant
 * verifier, and every conformant signature it received was rejected in turn.
 *
 * Both directions are needed: emitting requires DER to raw, and verifying requires raw back to
 * DER because `openssl_verify()` only accepts DER.
 *
 * The conversion is where implementations go wrong, in two specific places. A DER INTEGER is
 * *signed*, so a value whose top bit is set carries a leading `0x00` that is not part of the
 * coordinate and must be dropped. And it is *minimal*, so a coordinate that happens to start
 * with a zero byte -- roughly one in 256 -- arrives shorter than the curve width and must be
 * left-padded rather than written short. Getting either backwards produces signatures that
 * verify locally and nowhere else.
 *
 * @internal
 */
final class EcdsaSignatureCodec
{
    private const SEQUENCE = 0x30;
    private const INTEGER = 0x02;

    /**
     * @param positive-int $coordinateBytes 32 for P-256, 48 for P-384
     */
    public function derToRaw(string $der, int $coordinateBytes): string
    {
        $offset = 0;
        $this->expectTag($der, $offset, self::SEQUENCE, 'signature');
        $sequenceLength = $this->readLength($der, $offset);
        if ($offset + $sequenceLength !== strlen($der)) {
            throw new SignatureException('DER signature length does not match its contents.');
        }

        $r = $this->readInteger($der, $offset);
        $s = $this->readInteger($der, $offset);
        if ($offset !== strlen($der)) {
            throw new SignatureException('DER signature carries trailing bytes.');
        }

        return $this->pad($r, $coordinateBytes) . $this->pad($s, $coordinateBytes);
    }

    /**
     * @param positive-int $coordinateBytes 32 for P-256, 48 for P-384
     */
    public function rawToDer(string $raw, int $coordinateBytes): string
    {
        if (strlen($raw) !== $coordinateBytes * 2) {
            throw new SignatureException(sprintf(
                'Expected a %d-byte fixed-width ECDSA signature, got %d bytes.',
                $coordinateBytes * 2,
                strlen($raw),
            ));
        }

        $body = $this->integer(substr($raw, 0, $coordinateBytes)) . $this->integer(substr($raw, $coordinateBytes));

        return chr(self::SEQUENCE) . $this->encodeLength(strlen($body)) . $body;
    }

    private function expectTag(string $der, int &$offset, int $tag, string $what): void
    {
        if (! isset($der[$offset]) || ord($der[$offset]) !== $tag) {
            throw new SignatureException(sprintf('DER %s is not a %s.', $what, $tag === self::SEQUENCE ? 'sequence' : 'integer'));
        }

        ++$offset;
    }

    private function readLength(string $der, int &$offset): int
    {
        if (! isset($der[$offset])) {
            throw new SignatureException('DER signature is truncated.');
        }

        $first = ord($der[$offset]);
        ++$offset;

        if ($first < 0x80) {
            return $first;
        }

        $byteCount = $first & 0x7F;
        if ($byteCount === 0 || $byteCount > 4 || strlen($der) < $offset + $byteCount) {
            throw new SignatureException('DER signature has an unsupported length encoding.');
        }

        $length = 0;
        for ($i = 0; $i < $byteCount; ++$i) {
            $length = ($length << 8) | ord($der[$offset + $i]);
        }

        $offset += $byteCount;

        return $length;
    }

    private function readInteger(string $der, int &$offset): string
    {
        $this->expectTag($der, $offset, self::INTEGER, 'component');
        $length = $this->readLength($der, $offset);
        if ($length === 0 || strlen($der) < $offset + $length) {
            throw new SignatureException('DER signature integer is truncated.');
        }

        $value = substr($der, $offset, $length);
        $offset += $length;

        // Drop the sign padding a DER INTEGER carries when the top bit is set, plus any other
        // leading zeros. What is left is the coordinate.
        return ltrim($value, "\x00");
    }

    private function pad(string $value, int $coordinateBytes): string
    {
        if (strlen($value) > $coordinateBytes) {
            throw new SignatureException(sprintf(
                'ECDSA signature component is %d bytes, wider than the %d-byte curve coordinate.',
                strlen($value),
                $coordinateBytes,
            ));
        }

        // A coordinate that starts with a zero byte comes out of DER short, because DER stores
        // it minimally. Written short it is not a valid fixed-width signature.
        return str_pad($value, $coordinateBytes, "\x00", STR_PAD_LEFT);
    }

    private function integer(string $coordinate): string
    {
        $value = ltrim($coordinate, "\x00");
        if ($value === '') {
            $value = "\x00";
        } elseif ((ord($value[0]) & 0x80) !== 0) {
            // A DER INTEGER is signed, so a coordinate with its top bit set needs a leading
            // zero or it reads as negative.
            $value = "\x00" . $value;
        }

        return chr(self::INTEGER) . $this->encodeLength(strlen($value)) . $value;
    }

    private function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        for ($value = $length; $value > 0; $value >>= 8) {
            $bytes = chr($value & 0xFF) . $bytes;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
