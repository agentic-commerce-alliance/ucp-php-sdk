<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Webhook;

final readonly class WebhookDispatchResult
{
    /**
     * @param array<string, string> $responseHeaders
     */
    public function __construct(
        public string $targetUrl,
        public int $statusCode,
        public bool $successful,
        public bool $retryable = false,
        public array $responseHeaders = [],
        public ?string $responseBody = null,
    ) {
    }
}
