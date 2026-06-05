<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Symfony\Bridge\DefaultStorage\DefaultPrivateKeyEncryptor;

final class DefaultPrivateKeyEncryptorTest extends TestCase
{
    #[Test]
    public function itBindsCiphertextToTheProvidedContext(): void
    {
        $encryptor = new DefaultPrivateKeyEncryptor('test-secret');
        $cipherText = $encryptor->encrypt('private-key', 'kid-1');

        self::assertSame('private-key', $encryptor->decrypt($cipherText, 'kid-1'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to decrypt private key material.');
        $encryptor->decrypt($cipherText, 'kid-2');
    }

    #[Test]
    public function itRejectsLegacyOrTruncatedPayloads(): void
    {
        $encryptor = new DefaultPrivateKeyEncryptor('test-secret');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Encrypted private key payload is malformed.');
        $encryptor->decrypt(base64_encode(random_bytes(32)), 'kid-1');
    }
}
