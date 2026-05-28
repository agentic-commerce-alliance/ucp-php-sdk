<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

interface SchemaValidatorInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function validate(string $schemaName, array $payload): void;
}
