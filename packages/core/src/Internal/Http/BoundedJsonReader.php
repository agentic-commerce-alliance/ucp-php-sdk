<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Http;

use Ucp\Sdk\Exception\AgentProfileException;
use Ucp\Sdk\Exception\UcpException;
use Ucp\Sdk\Model\Http\HttpResponseInterface;
use Ucp\Sdk\Service\HttpClientInterface;

/**
 * Reads the JSON object an agent-controlled URL answers with, without trusting its size.
 *
 * Shared by the profile and key-directory fetchers, whose destinations a caller chose. The
 * declared `Content-Length` is checked first and the body is capped again while streaming, so
 * a hostile endpoint cannot answer with an unbounded body either way.
 *
 * @internal
 */
final class BoundedJsonReader
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int $timeoutSeconds,
        private readonly int $maxResponseBytes,
    ) {
    }

    /**
     * @param array<string, list<string>> $headers the response headers, already read by the caller
     *
     * @return array<mixed>
     */
    public function read(HttpResponseInterface $response, array $headers, string $uri): array
    {
        $contentLength = isset($headers['content-length'][0]) ? (int) $headers['content-length'][0] : null;
        if ($contentLength !== null && $contentLength > $this->maxResponseBytes) {
            throw AgentProfileException::tooLarge($uri, $this->maxResponseBytes);
        }

        $content = '';
        foreach ($this->httpClient->stream($response, $this->timeoutSeconds) as $chunk) {
            if ($chunk->isTimeout() || $chunk->isFirst()) {
                continue;
            }

            $content .= $chunk->getContent();

            // Checked while streaming rather than after: a declared Content-Length is the
            // sender's claim, and this is the part that holds when the claim is a lie.
            if (strlen($content) > $this->maxResponseBytes) {
                $response->cancel();

                throw AgentProfileException::tooLarge($uri, $this->maxResponseBytes);
            }
        }

        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw AgentProfileException::invalid($uri, 'the response body does not decode to a JSON object.');
        }

        return $payload;
    }

    /**
     * The exception a failed fetch surfaces as when no stale copy can stand in for it.
     */
    public static function failure(\Throwable $exception, string $uri): UcpException
    {
        if ($exception instanceof UcpException) {
            return $exception;
        }

        if ($exception instanceof \JsonException) {
            return AgentProfileException::invalid($uri, $exception->getMessage(), $exception);
        }

        return AgentProfileException::unreachable($uri, $exception);
    }
}
