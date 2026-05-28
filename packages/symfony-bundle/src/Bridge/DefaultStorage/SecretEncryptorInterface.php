<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DefaultStorage;

interface SecretEncryptorInterface
{
    public function encrypt(string $plainText, string $context = ''): string;

    public function decrypt(string $cipherText, string $context = ''): string;
}
