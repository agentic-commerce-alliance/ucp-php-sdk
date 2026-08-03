<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Http;

use Ucp\Sdk\Exception\AgentProfileException;
use Ucp\Sdk\Exception\UcpException;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Repository\PlatformProfileCacheRepositoryInterface;
use Ucp\Sdk\Service\AgentProfileFetcherInterface;
use Ucp\Sdk\Service\HttpClientInterface;

/** @internal */
final class HttpAgentProfileFetcher implements AgentProfileFetcherInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PlatformProfileCacheRepositoryInterface $cacheRepository,
        private readonly UrlSafetyValidator $urlSafetyValidator,
        private readonly int $timeoutSeconds = 5,
        private readonly int $maxResponseBytes = 1048576,
    ) {
    }

    public function fetch(string $uri): PlatformProfile
    {
        $validatedUri = $this->urlSafetyValidator->validateAndResolve($uri);

        $cached = $this->cacheRepository->find($uri);
        if ($cached !== null) {
            return $cached;
        }

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
                if (strlen($content) > $this->maxResponseBytes) {
                    $response->cancel();

                    throw AgentProfileException::tooLarge($uri, $this->maxResponseBytes);
                }
            }

            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw AgentProfileException::invalid($uri, 'the response body does not decode to a JSON object.');
            }

            $profile = PlatformProfile::fromArray($payload);
            $this->cacheRepository->save($uri, $profile);

            return $profile;
        } catch (\Throwable $exception) {
            if ($stale !== null) {
                return $stale;
            }

            if ($exception instanceof UcpException) {
                throw $exception;
            }

            if ($exception instanceof \JsonException) {
                throw AgentProfileException::invalid($uri, $exception->getMessage(), $exception);
            }

            throw AgentProfileException::unreachable($uri, $exception);
        }
    }
}
