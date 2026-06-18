<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Model\Http\HttpResponseInterface;

interface HttpClientInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): HttpResponseInterface;

    /**
     * @return iterable<\Ucp\Sdk\Model\Http\HttpResponseChunkInterface>
     */
    public function stream(HttpResponseInterface $response, ?float $timeout = null): iterable;
}
