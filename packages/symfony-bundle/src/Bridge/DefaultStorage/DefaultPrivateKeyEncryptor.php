<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DefaultStorage;

/** @internal */
final class DefaultPrivateKeyEncryptor implements SecretEncryptorInterface
{
    private const VERSION = "\x01";
    private const SALT_LENGTH = 16;
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const MIN_PAYLOAD_LENGTH = 46;

    public function __construct(
        private readonly string $secret,
    ) {
    }

    public function encrypt(string $plainText, string $context = ''): string
    {
        if ($plainText === '') {
            throw new \RuntimeException('Private key material must not be empty.');
        }

        $salt = random_bytes(self::SALT_LENGTH);
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $key = $this->deriveKey($salt);
        $cipherText = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $context, self::TAG_LENGTH);

        if ($cipherText === false) {
            throw new \RuntimeException('Unable to encrypt private key material.');
        }

        return base64_encode(self::VERSION . $salt . $iv . $tag . $cipherText);
    }

    public function decrypt(string $cipherText, string $context = ''): string
    {
        $payload = base64_decode($cipherText, true);
        if ($payload === false || strlen($payload) < self::MIN_PAYLOAD_LENGTH) {
            throw new \RuntimeException('Encrypted private key payload is malformed.');
        }

        if (! str_starts_with($payload, self::VERSION)) {
            throw new \RuntimeException('Encrypted private key payload uses an unsupported format.');
        }

        $offset = 1;
        $salt = substr($payload, $offset, self::SALT_LENGTH);
        $offset += self::SALT_LENGTH;
        $iv = substr($payload, $offset, self::IV_LENGTH);
        $offset += self::IV_LENGTH;
        $tag = substr($payload, $offset, self::TAG_LENGTH);
        $offset += self::TAG_LENGTH;
        $cipher = substr($payload, $offset);
        if ($cipher === '') {
            throw new \RuntimeException('Encrypted private key payload is malformed.');
        }

        $key = $this->deriveKey($salt);
        $plainText = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $context);

        if ($plainText === false) {
            throw new \RuntimeException('Unable to decrypt private key material.');
        }

        return $plainText;
    }

    private function deriveKey(string $salt): string
    {
        $key = hash_hkdf('sha256', $this->secret, 32, 'ucp-sdk/private-key/v1', $salt);
        if (strlen($key) !== 32) {
            throw new \RuntimeException('Unable to derive an encryption key for private key material.');
        }

        return $key;
    }
}
