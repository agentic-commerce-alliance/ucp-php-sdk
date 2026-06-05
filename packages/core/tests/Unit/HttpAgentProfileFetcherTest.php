<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Ucp\Sdk\Internal\Http\HttpAgentProfileFetcher;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Repository\PlatformProfileCacheRepositoryInterface;

final class HttpAgentProfileFetcherTest extends TestCase
{
    #[Test]
    public function itReturnsAStaleCachedProfileWhenTheRemoteFetchFails(): void
    {
        $staleProfile = new PlatformProfile('2026-04-08', [], [], [], [], [
            '2026-04-08' => 'https://platform.example/.well-known/ucp',
        ]);
        $cacheRepository = new RecordingPlatformProfileCacheRepository(null, $staleProfile);

        $fetcher = new HttpAgentProfileFetcher(
            new RecordingHttpClient(
                new RecordingResponse(500),
                [],
            ),
            $cacheRepository,
            new UrlSafetyValidator(
                ['platform.example'],
                static fn (string $host): array => $host === 'platform.example' ? ['203.0.113.10'] : [],
            ),
        );

        $profile = $fetcher->fetch('https://platform.example/.well-known/ucp');

        self::assertSame($staleProfile, $profile);
        self::assertCount(0, $cacheRepository->savedProfiles);
    }

    #[Test]
    public function itUsesExpectedRequestOptionsAndCachesSuccessfulProfiles(): void
    {
        $body = '{"ucp":{"version":"2026-04-08","services":{},"capabilities":{},"payment_handlers":{}},"signing_keys":[]}';
        $response = new RecordingResponse(200, ['content-length' => [(string) strlen($body)]]);
        $cacheRepository = new RecordingPlatformProfileCacheRepository();
        $client = new RecordingHttpClient(
            $response,
            [
                new RecordingChunk(first: true, content: 'ignored-first-chunk'),
                new RecordingChunk(timeout: true, content: 'ignored-timeout-chunk'),
                new RecordingChunk(content: substr($body, 0, 40), offset: 0),
                new RecordingChunk(content: substr($body, 40), offset: 40),
                new RecordingChunk(last: true, offset: strlen($body)),
            ],
        );

        $fetcher = new HttpAgentProfileFetcher(
            $client,
            $cacheRepository,
            new UrlSafetyValidator(
                ['platform.example'],
                static fn (string $host): array => $host === 'platform.example' ? ['203.0.113.10'] : [],
            ),
            timeoutSeconds: 7,
        );

        $profile = $fetcher->fetch('https://platform.example/.well-known/ucp');

        self::assertSame('GET', $client->method);
        self::assertSame('https://platform.example/.well-known/ucp', $client->url);
        self::assertSame(['Accept' => 'application/json'], $client->options['headers']);
        self::assertSame(7, $client->options['timeout']);
        self::assertSame(0, $client->options['max_redirects']);
        self::assertFalse($client->options['buffer']);
        self::assertSame(['platform.example' => '203.0.113.10'], $client->options['resolve']);
        self::assertSame(7.0, $client->streamTimeout);
        self::assertFalse($response->lastGetHeadersThrowArgument);
        self::assertFalse($response->cancelled);
        self::assertCount(1, $cacheRepository->savedProfiles);
        self::assertSame('https://platform.example/.well-known/ucp', $cacheRepository->savedProfiles[0]['uri']);
        self::assertEquals($profile, $cacheRepository->savedProfiles[0]['profile']);
    }

    #[Test]
    public function itAllowsResponsesWhoseContentLengthExactlyMatchesTheConfiguredByteLimit(): void
    {
        $body = '{"ucp":{"version":"2026-04-08","services":{},"capabilities":{},"payment_handlers":{}},"signing_keys":[]}';
        $cacheRepository = new RecordingPlatformProfileCacheRepository();
        $response = new RecordingResponse(200, ['content-length' => [(string) strlen($body)]]);
        $fetcher = new HttpAgentProfileFetcher(
            new RecordingHttpClient(
                $response,
                [new RecordingChunk(content: $body, offset: 0)],
            ),
            $cacheRepository,
            new UrlSafetyValidator(
                ['platform.example'],
                static fn (string $host): array => $host === 'platform.example' ? ['203.0.113.10'] : [],
            ),
            maxResponseBytes: strlen($body),
        );

        $profile = $fetcher->fetch('https://platform.example/.well-known/ucp');

        self::assertSame('2026-04-08', $profile->version);
        self::assertFalse($response->cancelled);
        self::assertCount(1, $cacheRepository->savedProfiles);
    }

    #[Test]
    public function itRejectsNonSuccessfulResponsesWhenNoStaleProfileExists(): void
    {
        $cacheRepository = new RecordingPlatformProfileCacheRepository();
        $client = new RecordingHttpClient(
            new RecordingResponse(500),
            [new RecordingChunk(content: '{}', offset: 0)],
        );
        $fetcher = new HttpAgentProfileFetcher(
            $client,
            $cacheRepository,
            new UrlSafetyValidator(
                ['platform.example'],
                static fn (string $host): array => $host === 'platform.example' ? ['203.0.113.10'] : [],
            ),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Platform profile fetch failed with a non-200 status code.');

        try {
            $fetcher->fetch('https://platform.example/.well-known/ucp');
        } finally {
            self::assertSame(0.0, $client->streamTimeout);
            self::assertCount(0, $cacheRepository->savedProfiles);
        }
    }

    #[Test]
    public function itRejectsResponsesThatExceedTheConfiguredByteLimitWhileStreamingAndCancelsTheResponse(): void
    {
        $response = new RecordingResponse(200);
        $cacheRepository = new RecordingPlatformProfileCacheRepository();
        $fetcher = new HttpAgentProfileFetcher(
            new RecordingHttpClient(
                $response,
                [new RecordingChunk(content: str_repeat('a', 2048), offset: 0)],
            ),
            $cacheRepository,
            new UrlSafetyValidator(
                ['platform.example'],
                static fn (string $host): array => $host === 'platform.example' ? ['203.0.113.10'] : [],
            ),
            maxResponseBytes: 512,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Platform profile response exceeded the maximum allowed size.');

        try {
            $fetcher->fetch('https://platform.example/.well-known/ucp');
        } finally {
            self::assertTrue($response->cancelled);
            self::assertCount(0, $cacheRepository->savedProfiles);
        }
    }

    #[Test]
    public function itRejectsContentLengthHeadersThatExceedTheConfiguredByteLimitBeforeStreaming(): void
    {
        $response = new RecordingResponse(200, ['content-length' => ['513']]);
        $client = new RecordingHttpClient(
            $response,
            [new RecordingChunk(content: '{}', offset: 0)],
        );
        $cacheRepository = new RecordingPlatformProfileCacheRepository();
        $fetcher = new HttpAgentProfileFetcher(
            $client,
            $cacheRepository,
            new UrlSafetyValidator(
                ['platform.example'],
                static fn (string $host): array => $host === 'platform.example' ? ['203.0.113.10'] : [],
            ),
            maxResponseBytes: 512,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Platform profile response exceeded the maximum allowed size.');

        try {
            $fetcher->fetch('https://platform.example/.well-known/ucp');
        } finally {
            self::assertFalse($response->cancelled);
            self::assertSame(0.0, $client->streamTimeout);
            self::assertCount(0, $cacheRepository->savedProfiles);
        }
    }
}

final class RecordingPlatformProfileCacheRepository implements PlatformProfileCacheRepositoryInterface
{
    /** @var list<array{uri: string, profile: PlatformProfile}> */
    public array $savedProfiles = [];

    public function __construct(
        private ?PlatformProfile $freshProfile = null,
        private ?PlatformProfile $staleProfile = null,
    ) {
    }

    public function save(string $uri, PlatformProfile $profile): void
    {
        $this->freshProfile = $profile;
        $this->savedProfiles[] = ['uri' => $uri, 'profile' => $profile];
    }

    public function find(string $uri, bool $allowExpired = false): ?PlatformProfile
    {
        return $allowExpired ? $this->staleProfile : $this->freshProfile;
    }

    public function all(bool $allowExpired = false): array
    {
        return [];
    }

    public function delete(string $uri): bool
    {
        return false;
    }

    public function purgeExpired(int $olderThanUnixTimestamp): void
    {
    }
}

final class RecordingHttpClient implements HttpClientInterface
{
    public string $method = '';
    public string $url = '';

    /** @var array<string, mixed> */
    public array $options = [];

    public float $streamTimeout = 0.0;

    /**
     * @param list<RecordingChunk> $chunks
     */
    public function __construct(
        private readonly RecordingResponse $response,
        private readonly array $chunks,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->method = $method;
        $this->url = $url;
        $this->options = $options;

        return $this->response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $this->streamTimeout = (float) ($timeout ?? 0.0);

        return new RecordingResponseStream($this->response, $this->chunks);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return $this;
    }
}

final class RecordingResponse implements ResponseInterface
{
    public bool $cancelled = false;
    public bool $lastGetHeadersThrowArgument = true;

    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly array $headers = [],
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(bool $throw = true): array
    {
        $this->lastGetHeadersThrowArgument = $throw;

        return $this->headers;
    }

    public function getContent(bool $throw = true): string
    {
        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $throw = true): array
    {
        return [];
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function getInfo(?string $type = null): mixed
    {
        return null;
    }
}

final class RecordingChunk implements ChunkInterface
{
    public function __construct(
        private readonly bool $timeout = false,
        private readonly bool $first = false,
        private readonly bool $last = false,
        private readonly string $content = '',
        private readonly int $offset = 0,
    ) {
    }

    public function isTimeout(): bool
    {
        return $this->timeout;
    }

    public function isFirst(): bool
    {
        return $this->first;
    }

    public function isLast(): bool
    {
        return $this->last;
    }

    /**
     * @return array{0: int, 1: array<string, list<string>>}|null
     */
    public function getInformationalStatus(): ?array
    {
        return null;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getError(): ?string
    {
        return null;
    }
}

final class RecordingResponseStream implements ResponseStreamInterface
{
    private int $index = 0;

    /**
     * @param list<RecordingChunk> $chunks
     */
    public function __construct(
        private readonly ResponseInterface $response,
        private readonly array $chunks,
    ) {
    }

    public function current(): ChunkInterface
    {
        return $this->chunks[$this->index];
    }

    public function next(): void
    {
        ++$this->index;
    }

    public function key(): ResponseInterface
    {
        return $this->response;
    }

    public function valid(): bool
    {
        return isset($this->chunks[$this->index]);
    }

    public function rewind(): void
    {
        $this->index = 0;
    }
}
