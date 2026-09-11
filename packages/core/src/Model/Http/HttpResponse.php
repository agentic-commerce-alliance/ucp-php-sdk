<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Http;

/**
 * A response as the signer sees it.
 *
 * Deliberately not the transport's own response object: signing is over the bytes that will be
 * sent, and a framework response is still mutable when a listener gets hold of it. Copying the
 * status, headers and body out at the moment of signing is what makes "the signature covers what
 * was sent" a statement about this value rather than a hope about ordering.
 */
final class HttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }
}
