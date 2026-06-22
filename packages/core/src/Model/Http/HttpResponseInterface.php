<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Http;

interface HttpResponseInterface
{
    public function getStatusCode(): int;

    /**
     * @return array<string, list<string>>
     */
    public function getHeaders(bool $throw = true): array;

    public function cancel(): void;
}
