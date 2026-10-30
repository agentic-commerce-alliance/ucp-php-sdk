<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Http\HttpAgentKeyDirectoryFetcher;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;
use Ucp\Sdk\Model\Http\HttpResponseChunkInterface;
use Ucp\Sdk\Model\Http\HttpResponseInterface;
use Ucp\Sdk\Model\Security\AgentKeyDirectory;
use Ucp\Sdk\Repository\AgentKeyDirectoryCacheRepositoryInterface;
use Ucp\Sdk\Service\HttpClientInterface;

final class HttpAgentKeyDirectoryFetcherTest extends TestCase
{
    #[Test]
    public function itReadsAndCachesADirectoryOfExactlyTheConfiguredByteLimit(): void
    {
        $keyManager = new DefaultSigningKeyManager();
        $body = json_encode(
            ['keys' => [$keyManager->toPublicKey($keyManager->generate('kid-agent'))->toJwk()]],
            \JSON_THROW_ON_ERROR,
        );

        $response = $this->createMock(HttpResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaders')->willReturn([]);
        $chunk = $this->createMock(HttpResponseChunkInterface::class);
        $chunk->method('getContent')->willReturn($body);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())->method('request')->with('GET', 'https://agent.example/keys', [
            'headers' => ['Accept' => 'application/json'],
            'timeout' => 3,
            'max_redirects' => 0,
            'buffer' => false,
            'resolve' => ['agent.example' => '203.0.113.20'],
        ])->willReturn($response);
        $httpClient->expects(self::once())->method('stream')->with($response, 3)->willReturn([$chunk]);
        $cacheRepository = $this->createMock(AgentKeyDirectoryCacheRepositoryInterface::class);
        $cacheRepository->expects(self::once())->method('save')
            ->with('https://agent.example/keys', self::isInstanceOf(AgentKeyDirectory::class));

        $fetcher = new HttpAgentKeyDirectoryFetcher(
            $httpClient,
            $cacheRepository,
            new UrlSafetyValidator(
                ['agent.example'],
                static fn (string $host): array => $host === 'agent.example' ? ['203.0.113.20'] : [],
            ),
            timeoutSeconds: 3,
            maxResponseBytes: \strlen($body),
        );

        $directory = $fetcher->fetch('https://agent.example/keys');

        self::assertSame('kid-agent', $directory->keys[0]->kid);
    }
}
