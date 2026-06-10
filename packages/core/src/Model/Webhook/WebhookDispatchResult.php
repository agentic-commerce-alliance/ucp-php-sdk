<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Webhook;

final class WebhookDispatchResult
{
    /**
     * @param array<string, string> $responseHeaders
     */
    public function __construct(
        public readonly string $targetUrl,
        public readonly int $statusCode,
        public readonly bool $successful,
        public readonly bool $retryable = false,
        public readonly array $responseHeaders = [],
        public readonly ?string $responseBody = null,
    ) {
    }
}
