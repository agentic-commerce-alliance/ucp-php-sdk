<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Http;

final class HttpRequest
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    public function __construct(
        public readonly string $method,
        public readonly string $absoluteUri,
        public readonly array $headers = [],
        public readonly array $query = [],
        public readonly string $body = '',
    ) {
    }
}
