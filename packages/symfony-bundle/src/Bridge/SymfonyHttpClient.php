<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge;

use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as SymfonyHttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface as SymfonyResponseInterface;
use Ucp\Sdk\Model\Http\HttpResponseChunkInterface;
use Ucp\Sdk\Model\Http\HttpResponseInterface;
use Ucp\Sdk\Service\HttpClientInterface;

/** @internal */
final class SymfonyHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly SymfonyHttpClientInterface $httpClient,
    ) {
    }

    public function request(string $method, string $url, array $options = []): HttpResponseInterface
    {
        return new SymfonyHttpResponse($this->httpClient->request($method, $url, $options));
    }

    public function stream(HttpResponseInterface $response, ?float $timeout = null): iterable
    {
        if (! $response instanceof SymfonyHttpResponse) {
            throw new \InvalidArgumentException('SymfonyHttpClient can only stream responses created by itself.');
        }

        foreach ($this->httpClient->stream($response->innerResponse(), $timeout) as $chunk) {
            yield new SymfonyHttpResponseChunk($chunk);
        }
    }
}

/** @internal */
final class SymfonyHttpResponse implements HttpResponseInterface
{
    public function __construct(
        private readonly SymfonyResponseInterface $response,
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->response->getHeaders($throw);
    }

    public function cancel(): void
    {
        $this->response->cancel();
    }

    public function innerResponse(): SymfonyResponseInterface
    {
        return $this->response;
    }
}

/** @internal */
final class SymfonyHttpResponseChunk implements HttpResponseChunkInterface
{
    public function __construct(
        private readonly ChunkInterface $chunk,
    ) {
    }

    public function isTimeout(): bool
    {
        return $this->chunk->isTimeout();
    }

    public function isFirst(): bool
    {
        return $this->chunk->isFirst();
    }

    public function getContent(): string
    {
        return $this->chunk->getContent();
    }
}
