<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Http;

final readonly class HttpRequest
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    public function __construct(
        public string $method,
        public string $absoluteUri,
        public array $headers = [],
        public array $query = [],
        public string $body = '',
    ) {
    }
}
