<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\AgentProfileException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Internal\Http\HttpAgentProfileFetcher;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;
use Ucp\Sdk\Model\Http\HttpResponseChunkInterface;
use Ucp\Sdk\Model\Http\HttpResponseInterface;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Repository\PlatformProfileCacheRepositoryInterface;
use Ucp\Sdk\Service\HttpClientInterface;

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
                new RecordingChunk(content: substr($body, 0, 40)),
                new RecordingChunk(content: substr($body, 40)),
                new RecordingChunk(),
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
                [new RecordingChunk(content: $body)],
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
            [new RecordingChunk(content: '{}')],
        );
        $fetcher = new HttpAgentProfileFetcher(
            $client,
            $cacheRepository,
            new UrlSafetyValidator(
                ['platform.example'],
                static fn (string $host): array => $host === 'platform.example' ? ['203.0.113.10'] : [],
            ),
        );

        try {
            $fetcher->fetch('https://platform.example/.well-known/ucp');
            self::fail('Expected an AgentProfileException.');
        } catch (AgentProfileException $exception) {
            self::assertSame('agent_profile_unavailable', $exception->errorCode);
            self::assertSame(
                'Platform profile fetch from "https://platform.example/.well-known/ucp" failed with HTTP status 500.',
                $exception->getMessage(),
            );
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
                [new RecordingChunk(content: str_repeat('a', 2048))],
            ),
            $cacheRepository,
            new UrlSafetyValidator(
                ['platform.example'],
                static fn (string $host): array => $host === 'platform.example' ? ['203.0.113.10'] : [],
            ),
            maxResponseBytes: 512,
        );

        try {
            $fetcher->fetch('https://platform.example/.well-known/ucp');
            self::fail('Expected an AgentProfileException.');
        } catch (AgentProfileException $exception) {
            self::assertSame('agent_profile_too_large', $exception->errorCode);
            self::assertSame(
                'Platform profile response from "https://platform.example/.well-known/ucp" exceeded the maximum allowed size of 512 bytes.',
                $exception->getMessage(),
            );
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
            [new RecordingChunk(content: '{}')],
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

        try {
            $fetcher->fetch('https://platform.example/.well-known/ucp');
            self::fail('Expected an AgentProfileException.');
        } catch (AgentProfileException $exception) {
            self::assertSame('agent_profile_too_large', $exception->errorCode);
        } finally {
            self::assertFalse($response->cancelled);
            self::assertSame(0.0, $client->streamTimeout);
            self::assertCount(0, $cacheRepository->savedProfiles);
        }
    }

    #[Test]
    public function itRejectsResponseBodiesThatAreNotValidJson(): void
    {
        $cacheRepository = new RecordingPlatformProfileCacheRepository();
        $fetcher = new HttpAgentProfileFetcher(
            new RecordingHttpClient(
                new RecordingResponse(200),
                [new RecordingChunk(content: '<html>not json</html>')],
            ),
            $cacheRepository,
            new UrlSafetyValidator(
                ['platform.example'],
                static fn (string $host): array => $host === 'platform.example' ? ['203.0.113.10'] : [],
            ),
        );

        try {
            $fetcher->fetch('https://platform.example/.well-known/ucp');
            self::fail('Expected an AgentProfileException.');
        } catch (AgentProfileException $exception) {
            self::assertSame('agent_profile_invalid', $exception->errorCode);
            self::assertStringStartsWith(
                'Platform profile from "https://platform.example/.well-known/ucp" is not a valid UCP profile document:',
                $exception->getMessage(),
            );
            self::assertInstanceOf(\JsonException::class, $exception->getPrevious());
        } finally {
            self::assertCount(0, $cacheRepository->savedProfiles);
        }
    }

    #[Test]
    public function itRejectsResponseBodiesThatDecodeToAJsonScalar(): void
    {
        $cacheRepository = new RecordingPlatformProfileCacheRepository();
        $fetcher = new HttpAgentProfileFetcher(
            new RecordingHttpClient(
                new RecordingResponse(200),
                [new RecordingChunk(content: '"just-a-string"')],
            ),
            $cacheRepository,
            new UrlSafetyValidator(
                ['platform.example'],
                static fn (string $host): array => $host === 'platform.example' ? ['203.0.113.10'] : [],
            ),
        );

        try {
            $fetcher->fetch('https://platform.example/.well-known/ucp');
            self::fail('Expected an AgentProfileException.');
        } catch (AgentProfileException $exception) {
            self::assertSame('agent_profile_invalid', $exception->errorCode);
            self::assertStringEndsWith('the response body does not decode to a JSON object.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        } finally {
            self::assertCount(0, $cacheRepository->savedProfiles);
        }
    }

    #[Test]
    public function itWrapsTransportFailuresInAnAgentProfileException(): void
    {
        $transportFailure = new \RuntimeException('Failed to connect to platform.example port 443.');
        $cacheRepository = new RecordingPlatformProfileCacheRepository();
        $fetcher = new HttpAgentProfileFetcher(
            RecordingHttpClient::failing($transportFailure),
            $cacheRepository,
            new UrlSafetyValidator(
                ['platform.example'],
                static fn (string $host): array => $host === 'platform.example' ? ['203.0.113.10'] : [],
            ),
        );

        try {
            $fetcher->fetch('https://platform.example/.well-known/ucp');
            self::fail('Expected an AgentProfileException.');
        } catch (AgentProfileException $exception) {
            self::assertSame('agent_profile_unreachable', $exception->errorCode);
            self::assertSame(
                'Platform profile at "https://platform.example/.well-known/ucp" could not be fetched: Failed to connect to platform.example port 443.',
                $exception->getMessage(),
            );
            self::assertSame($transportFailure, $exception->getPrevious());
        } finally {
            self::assertCount(0, $cacheRepository->savedProfiles);
        }
    }

    #[Test]
    public function itReturnsAStaleCachedProfileWhenTheTransportFails(): void
    {
        $staleProfile = new PlatformProfile('2026-04-08', [], [], [], [], [
            '2026-04-08' => 'https://platform.example/.well-known/ucp',
        ]);
        $cacheRepository = new RecordingPlatformProfileCacheRepository(null, $staleProfile);

        $fetcher = new HttpAgentProfileFetcher(
            RecordingHttpClient::failing(new \RuntimeException('Connection refused.')),
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
    public function itRejectsAndDoesNotCacheMalformedRemoteProfiles(): void
    {
        $body = '{"ucp":{"services":"bad","capabilities":{},"payment_handlers":{}},"signing_keys":[]}';
        $response = new RecordingResponse(200, ['content-length' => [(string) strlen($body)]]);
        $cacheRepository = new RecordingPlatformProfileCacheRepository();
        $fetcher = new HttpAgentProfileFetcher(
            new RecordingHttpClient(
                $response,
                [new RecordingChunk(content: $body)],
            ),
            $cacheRepository,
            new UrlSafetyValidator(
                ['platform.example'],
                static fn (string $host): array => $host === 'platform.example' ? ['203.0.113.10'] : [],
            ),
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Platform profile section "services" must be an object.');

        try {
            $fetcher->fetch('https://platform.example/.well-known/ucp');
        } finally {
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
        private readonly ?\Throwable $requestException = null,
    ) {
    }

    public static function failing(\Throwable $throwable): self
    {
        return new self(new RecordingResponse(200), [], $throwable);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): HttpResponseInterface
    {
        $this->method = $method;
        $this->url = $url;
        $this->options = $options;

        if ($this->requestException !== null) {
            throw $this->requestException;
        }

        return $this->response;
    }

    public function stream(HttpResponseInterface $response, ?float $timeout = null): iterable
    {
        $this->streamTimeout = (float) ($timeout ?? 0.0);

        return $this->chunks;
    }
}

final class RecordingResponse implements HttpResponseInterface
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

    public function cancel(): void
    {
        $this->cancelled = true;
    }
}

final class RecordingChunk implements HttpResponseChunkInterface
{
    public function __construct(
        private readonly bool $timeout = false,
        private readonly bool $first = false,
        private readonly string $content = '',
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

    public function getContent(): string
    {
        return $this->content;
    }
}
