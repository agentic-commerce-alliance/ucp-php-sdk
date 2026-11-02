<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Http;

use Ucp\Sdk\Exception\AgentProfileException;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;
use Ucp\Sdk\Model\Security\AgentKeyDirectory;
use Ucp\Sdk\Repository\AgentKeyDirectoryCacheRepositoryInterface;
use Ucp\Sdk\Service\AgentKeyDirectoryFetcherInterface;
use Ucp\Sdk\Service\HttpClientInterface;

/**
 * Fetches the JWK Set an agent publishes at its `Signature-Agent` URL.
 *
 * The URL arrives in a request header, which makes this an outbound request a caller chose the
 * destination of. Every guard here exists for that reason rather than for tidiness: the host is
 * checked against the configured allow-list before anything is sent, the resolved address is
 * pinned so a name cannot resolve differently between check and fetch, redirects are refused so
 * an allowed host cannot forward to a disallowed one, and the body is capped while streaming so
 * a hostile endpoint cannot answer with an unbounded one.
 *
 * @internal
 */
final class HttpAgentKeyDirectoryFetcher implements AgentKeyDirectoryFetcherInterface
{
    private readonly BoundedJsonReader $bodyReader;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AgentKeyDirectoryCacheRepositoryInterface $cacheRepository,
        private readonly UrlSafetyValidator $urlSafetyValidator,
        private readonly int $timeoutSeconds = 5,
        int $maxResponseBytes = 1048576,
    ) {
        $this->bodyReader = new BoundedJsonReader($httpClient, $timeoutSeconds, $maxResponseBytes);
    }

    public function fetch(string $uri): AgentKeyDirectory
    {
        $validatedUri = $this->urlSafetyValidator->validateAndResolve($uri);

        $cached = $this->cacheRepository->find($uri);
        if ($cached !== null) {
            return $cached;
        }

        // A stale entry is better than no keys at all when the agent's directory is briefly
        // unreachable: the alternative is refusing every signed request from that agent because
        // its key server had a bad minute.
        $stale = $this->cacheRepository->find($uri, true);

        try {
            $response = $this->httpClient->request('GET', $uri, [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => $this->timeoutSeconds,
                'max_redirects' => 0,
                'buffer' => false,
                'resolve' => $validatedUri->resolveMap(),
            ]);

            if ($response->getStatusCode() !== 200) {
                throw AgentProfileException::unavailable($uri, $response->getStatusCode());
            }

            $headers = $response->getHeaders(false);
            $payload = $this->bodyReader->read($response, $headers, $uri);

            $directory = AgentKeyDirectory::fromArray($uri, $payload);
            $this->cacheRepository->save($uri, $directory);

            return $directory;
        } catch (\Throwable $exception) {
            if ($stale !== null) {
                return $stale;
            }

            throw BoundedJsonReader::failure($exception, $uri);
        }
    }
}
