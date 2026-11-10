<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Enum\PlatformPolicy;
use Ucp\Sdk\Exception\AgentProfileException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Internal\Http\DiscoveryGuard;
use Ucp\Sdk\Internal\Http\HttpAgentProfileFetcher;
use Ucp\Sdk\Internal\Repository\InMemoryDiscoveryBudgetRepository;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;
use Ucp\Sdk\Model\Config\DiscoveryBudget;
use Ucp\Sdk\Model\Http\HttpResponseChunkInterface;
use Ucp\Sdk\Model\Http\HttpResponseInterface;
use Ucp\Sdk\Model\Profile\CachedPlatformProfile;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Repository\PlatformProfileCacheRepositoryInterface;
use Ucp\Sdk\Repository\RevalidatingPlatformProfileCacheRepositoryInterface;
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
                static fn (string $host): array => $host === 'platform.example' ? ['93.184.216.34'] : [],
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
                static fn (string $host): array => $host === 'platform.example' ? ['93.184.216.34'] : [],
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
        self::assertSame(['platform.example' => '93.184.216.34'], $client->options['resolve']);
        self::assertSame(7.0, $client->streamTimeout);
        self::assertFalse($response->lastGetHeadersThrowArgument);
        self::assertFalse($response->cancelled);
        self::assertCount(1, $cacheRepository->savedProfiles);
        self::assertSame('https://platform.example/.well-known/ucp', $cacheRepository->savedProfiles[0]['uri']);
        self::assertEquals($profile, $cacheRepository->savedProfiles[0]['profile']);
    }

    #[Test]
    public function itHonoursThePerCallAllowedProfileHostsOverride(): void
    {
        $body = '{"ucp":{"version":"2026-04-08","services":{},"capabilities":{},"payment_handlers":{}},"signing_keys":[]}';
        $response = new RecordingResponse(200, ['content-length' => [(string) strlen($body)]]);
        $cacheRepository = new RecordingPlatformProfileCacheRepository();
        $client = new RecordingHttpClient(
            $response,
            [
                new RecordingChunk(content: $body),
                new RecordingChunk(),
            ],
        );

        // Empty constructor allowlist would reject any public host — only the
        // per-call override (the resolved sales channel's hosts) lets it through.
        $fetcher = new HttpAgentProfileFetcher(
            $client,
            $cacheRepository,
            new UrlSafetyValidator(
                [],
                static fn (string $host): array => $host === 'platform.example' ? ['93.184.216.34'] : [],
            ),
        );

        $profile = $fetcher->fetchForAllowedHosts('https://platform.example/.well-known/ucp', ['platform.example']);

        self::assertInstanceOf(PlatformProfile::class, $profile);
        self::assertCount(1, $cacheRepository->savedProfiles);
        self::assertSame(['platform.example' => '93.184.216.34'], $client->options['resolve']);
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
                static fn (string $host): array => $host === 'platform.example' ? ['93.184.216.34'] : [],
            ),
            maxResponseBytes: strlen($body),
        );

        $profile = $fetcher->fetch('https://platform.example/.well-known/ucp');

        self::assertSame('2026-04-08', $profile->version);
        self::assertFalse($response->cancelled);
        self::assertCount(1, $cacheRepository->savedProfiles);
    }

    #[Test]
    public function itParsesTheWholeDocumentIncludingTheKeysAfterTheUcpObject(): void
    {
        $keyManager = new DefaultSigningKeyManager();
        $body = json_encode([
            'ucp' => ['version' => '2026-08-25', 'services' => [], 'capabilities' => [], 'payment_handlers' => []],
            'keys' => [$keyManager->toPublicKey($keyManager->generate('kid-platform'))->toJwk()],
        ], JSON_THROW_ON_ERROR);
        $fetcher = new HttpAgentProfileFetcher(
            new RecordingHttpClient(
                new RecordingResponse(200),
                [new RecordingChunk(content: $body)],
            ),
            new RecordingPlatformProfileCacheRepository(),
            new UrlSafetyValidator(
                ['platform.example'],
                static fn (string $host): array => $host === 'platform.example' ? ['93.184.216.34'] : [],
            ),
        );

        $profile = $fetcher->fetch('https://platform.example/.well-known/ucp');

        self::assertCount(1, $profile->signingKeys);
        self::assertSame('kid-platform', $profile->signingKeys[0]->kid);
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
                static fn (string $host): array => $host === 'platform.example' ? ['93.184.216.34'] : [],
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
                static fn (string $host): array => $host === 'platform.example' ? ['93.184.216.34'] : [],
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
                static fn (string $host): array => $host === 'platform.example' ? ['93.184.216.34'] : [],
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
                static fn (string $host): array => $host === 'platform.example' ? ['93.184.216.34'] : [],
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
                static fn (string $host): array => $host === 'platform.example' ? ['93.184.216.34'] : [],
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
                static fn (string $host): array => $host === 'platform.example' ? ['93.184.216.34'] : [],
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
                static fn (string $host): array => $host === 'platform.example' ? ['93.184.216.34'] : [],
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
                static fn (string $host): array => $host === 'platform.example' ? ['93.184.216.34'] : [],
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

    /**
     * The spec requires a platform to publish its profile with `Cache-Control: public, max-age`,
     * and this fetcher was ignoring it: every platform got the same 600 seconds. A platform that
     * rotates keys and says so with a short max-age was being answered from a stale cache.
     */
    /**
     * @param array<string, list<string>> $headers
     */
    #[Test]
    #[DataProvider('cacheControlProvider')]
    public function itCachesForAsLongAsThePlatformAsksWithinTheConfiguredBounds(array $headers, int $expectedTtl): void
    {
        $body = self::PROFILE_BODY;
        $cache = new RecordingRevalidatingCacheRepository();
        $client = new RecordingHttpClient(new RecordingResponse(200, $headers), [new RecordingChunk(content: $body)]);
        $fetcher = $this->fetcher($client, $cache, maxTtlSeconds: 600, minimumTtlSeconds: 60);

        $before = time();
        $fetcher->fetch('https://platform.example/.well-known/ucp');

        self::assertCount(1, $cache->savedEntries);
        $ttl = $cache->savedEntries[0]['expiresAt'] - $before;
        self::assertGreaterThanOrEqual($expectedTtl, $ttl);
        self::assertLessThanOrEqual($expectedTtl + 2, $ttl);
    }

    /**
     * @return iterable<string, array{array<string, list<string>>, int}>
     */
    public static function cacheControlProvider(): iterable
    {
        yield 'max-age within bounds is honoured' => [['cache-control' => ['public, max-age=120']], 120];
        yield 's-maxage wins over max-age for a shared cache' => [['cache-control' => ['max-age=500, s-maxage=90']], 90];
        yield 'below the spec floor is raised to it' => [['cache-control' => ['max-age=5']], 60];
        yield 'above the ceiling is capped' => [['cache-control' => ['max-age=86400']], 600];
        yield 'no directive means the configured ceiling' => [[], 600];
        yield 'no-store is the floor, not never' => [['cache-control' => ['no-store']], 60];
        yield 'a quoted value parses' => [['cache-control' => ['max-age="300"']], 300];
    }

    #[Test]
    public function itRevalidatesAStaleEntryWithItsEtagAndRenewsItOnA304(): void
    {
        $profile = PlatformProfile::fromArray(json_decode(self::PROFILE_BODY, true));
        $cache = new RecordingRevalidatingCacheRepository(new CachedPlatformProfile($profile, time() - 10, '"v1"'));
        $client = new RecordingHttpClient(new RecordingResponse(304, ['cache-control' => ['max-age=200'], 'etag' => ['"v1"']]), []);
        $fetcher = $this->fetcher($client, $cache);

        $before = time();
        $result = $fetcher->fetch('https://platform.example/.well-known/ucp');

        self::assertSame('"v1"', $client->options['headers']['If-None-Match'], 'A stale entry with a validator must be revalidated, not refetched blind.');
        self::assertSame($profile, $result);
        self::assertCount(1, $cache->savedEntries);
        self::assertSame('"v1"', $cache->savedEntries[0]['etag']);
        self::assertGreaterThanOrEqual(200, $cache->savedEntries[0]['expiresAt'] - $before);
    }

    #[Test]
    public function itStoresTheEtagAFreshProfileArrivesWithAndSendsNoneWhenItHasNone(): void
    {
        $cache = new RecordingRevalidatingCacheRepository();
        $client = new RecordingHttpClient(new RecordingResponse(200, ['etag' => [' W/"abc" ']]), [new RecordingChunk(content: self::PROFILE_BODY)]);
        $fetcher = $this->fetcher($client, $cache);

        $fetcher->fetch('https://platform.example/.well-known/ucp');

        self::assertArrayNotHasKey('If-None-Match', $client->options['headers']);
        self::assertSame('W/"abc"', $cache->savedEntries[0]['etag']);
    }

    #[Test]
    public function itReturnsTheStaleEntryWhenRevalidationFails(): void
    {
        $profile = PlatformProfile::fromArray(json_decode(self::PROFILE_BODY, true));
        $cache = new RecordingRevalidatingCacheRepository(new CachedPlatformProfile($profile, time() - 10, '"v1"'));
        $fetcher = $this->fetcher(RecordingHttpClient::failing(new \RuntimeException('down')), $cache);

        self::assertSame($profile, $fetcher->fetch('https://platform.example/.well-known/ucp'));
        self::assertSame([], $cache->savedEntries);
    }

    #[Test]
    public function itKeepsTheFixedTtlForARepositoryThatCannotStoreFreshness(): void
    {
        $cache = new RecordingPlatformProfileCacheRepository();
        $client = new RecordingHttpClient(new RecordingResponse(200, ['cache-control' => ['max-age=5'], 'etag' => ['"x"']]), [new RecordingChunk(content: self::PROFILE_BODY)]);
        $fetcher = $this->fetcher($client, $cache);

        $fetcher->fetch('https://platform.example/.well-known/ucp');

        self::assertCount(1, $cache->savedProfiles, 'The base interface only knows save(); the repository keeps deciding the TTL.');
        self::assertArrayNotHasKey('If-None-Match', $client->options['headers']);
    }

    private const PROFILE_BODY = '{"ucp":{"version":"2026-04-08","services":{},"capabilities":{},"payment_handlers":{}},"signing_keys":[]}';

    private function fetcher(
        RecordingHttpClient $client,
        PlatformProfileCacheRepositoryInterface $cache,
        int $maxTtlSeconds = 600,
        int $minimumTtlSeconds = 60,
    ): HttpAgentProfileFetcher {
        return new HttpAgentProfileFetcher(
            $client,
            $cache,
            new UrlSafetyValidator(
                ['platform.example'],
                static fn (string $host): array => $host === 'platform.example' ? ['93.184.216.34'] : [],
            ),
            maxTtlSeconds: $maxTtlSeconds,
            minimumTtlSeconds: $minimumTtlSeconds,
        );
    }

    /**
     * A platform outside the allowed hosts is discovery, and discovery is bounded: once the
     * window is full, the fetch is refused with how long to wait, and nothing goes on the wire.
     */
    #[Test]
    public function itDefersDiscoveryOnceTheWindowIsFull(): void
    {
        $body = '{"ucp":{"version":"2026-04-08","services":{},"capabilities":{},"payment_handlers":{}},"signing_keys":[]}';
        $client = new RecordingHttpClient(
            new RecordingResponse(200, ['content-length' => [(string) strlen($body)]]),
            [new RecordingChunk(content: $body), new RecordingChunk()],
        );
        $fetcher = new HttpAgentProfileFetcher(
            $client,
            new NullPlatformProfileCacheRepository(),
            new UrlSafetyValidator([], static fn (string $host): array => ['93.184.216.34']),
            discoveryGuard: new DiscoveryGuard(new InMemoryDiscoveryBudgetRepository(), new DiscoveryBudget(maxFetchesPerWindow: 1, windowSeconds: 60), PlatformPolicy::All),
        );

        $fetcher->fetchForAllowedHosts('https://first.example/.well-known/ucp', ['listed.example']);

        try {
            $fetcher->fetchForAllowedHosts('https://second.example/.well-known/ucp', ['listed.example']);
            self::fail('The second discovery in the window must be deferred.');
        } catch (AgentProfileException $exception) {
            self::assertSame('agent_profile_discovery_deferred', $exception->errorCode);
            self::assertNotNull($exception->retryAfterSeconds);
            self::assertGreaterThan(0, $exception->retryAfterSeconds);
            self::assertLessThanOrEqual(60, $exception->retryAfterSeconds);
        }
        self::assertSame(1, $client->requests, 'the deferred fetch never reaches the network');
    }

    /**
     * Pre-approved platforms "are not subject to discovery budget constraints": a listed host
     * is fetched however full the window is.
     */
    #[Test]
    public function itNeverCountsAListedHostAgainstTheDiscoveryBudget(): void
    {
        $body = '{"ucp":{"version":"2026-04-08","services":{},"capabilities":{},"payment_handlers":{}},"signing_keys":[]}';
        $client = new RecordingHttpClient(
            new RecordingResponse(200, ['content-length' => [(string) strlen($body)]]),
            [new RecordingChunk(content: $body), new RecordingChunk()],
        );
        $fetcher = new HttpAgentProfileFetcher(
            $client,
            new NullPlatformProfileCacheRepository(),
            new UrlSafetyValidator([], static fn (string $host): array => ['93.184.216.34']),
            discoveryGuard: new DiscoveryGuard(new InMemoryDiscoveryBudgetRepository(), new DiscoveryBudget(maxFetchesPerWindow: 1, windowSeconds: 60), PlatformPolicy::All),
        );
        $fetcher->fetchForAllowedHosts('https://unknown.example/.well-known/ucp', ['listed.example']);

        $profile = $fetcher->fetchForAllowedHosts('https://api.listed.example/.well-known/ucp', ['listed.example']);

        self::assertInstanceOf(PlatformProfile::class, $profile);
        self::assertSame(2, $client->requests);
    }

    /**
     * A cache hit is not discovery. A platform whose profile is fresh in the cache is served
     * from it, whatever the budget says.
     */
    #[Test]
    public function itServesAFreshCachedProfileWithoutTouchingTheDiscoveryBudget(): void
    {
        $cached = new PlatformProfile('2026-04-08', [], [], [], []);
        $client = new RecordingHttpClient(new RecordingResponse(500), []);
        $fetcher = new HttpAgentProfileFetcher(
            $client,
            new RecordingPlatformProfileCacheRepository($cached),
            new UrlSafetyValidator([], static fn (string $host): array => ['93.184.216.34']),
            discoveryGuard: new DiscoveryGuard(new InMemoryDiscoveryBudgetRepository(), new DiscoveryBudget(maxFetchesPerWindow: 1, windowSeconds: 60), PlatformPolicy::All),
        );
        $fetcher->fetchForAllowedHosts('https://other.example/.well-known/ucp', []);

        $profile = $fetcher->fetchForAllowedHosts('https://unknown.example/.well-known/ucp', []);

        self::assertSame($cached, $profile);
        self::assertSame(0, $client->requests);
    }

    /**
     * An origin whose fetch failed is left alone for a while: the next fetch is deferred with
     * the backoff, and the failure is not retried on every request.
     */
    #[Test]
    public function itBacksOffAnOriginAfterAFailedDiscovery(): void
    {
        $client = new RecordingHttpClient(new RecordingResponse(500), []);
        $budget = new InMemoryDiscoveryBudgetRepository();
        $fetcher = new HttpAgentProfileFetcher(
            $client,
            new RecordingPlatformProfileCacheRepository(),
            new UrlSafetyValidator([], static fn (string $host): array => ['93.184.216.34']),
            discoveryGuard: new DiscoveryGuard($budget, new DiscoveryBudget(maxFetchesPerWindow: 100, backoffBaseSeconds: 30), PlatformPolicy::All),
        );

        try {
            $fetcher->fetchForAllowedHosts('https://flaky.example/.well-known/ucp', []);
            self::fail('A 500 from the platform must fail the fetch.');
        } catch (AgentProfileException $exception) {
            self::assertSame('agent_profile_unavailable', $exception->errorCode);
        }

        try {
            $fetcher->fetchForAllowedHosts('https://flaky.example/.well-known/ucp', []);
            self::fail('The origin must be in backoff after a failure.');
        } catch (AgentProfileException $exception) {
            self::assertSame('agent_profile_discovery_deferred', $exception->errorCode);
            self::assertSame(30, $exception->retryAfterSeconds);
        }
        self::assertSame(1, $client->requests, 'the backed-off origin is not fetched again');
        self::assertNotNull($budget->retryAfter('https://flaky.example/.well-known/ucp'));
    }

    /**
     * A successful fetch ends the backoff, so one bad hour does not follow a platform around.
     */
    #[Test]
    public function itClearsTheBackoffOnceTheOriginAnswersAgain(): void
    {
        $body = '{"ucp":{"version":"2026-04-08","services":{},"capabilities":{},"payment_handlers":{}},"signing_keys":[]}';
        $budget = new InMemoryDiscoveryBudgetRepository();
        $budget->recordFailure('https://recovered.example/.well-known/ucp', time() - 1);
        $fetcher = new HttpAgentProfileFetcher(
            new RecordingHttpClient(
                new RecordingResponse(200, ['content-length' => [(string) strlen($body)]]),
                [new RecordingChunk(content: $body), new RecordingChunk()],
            ),
            new RecordingPlatformProfileCacheRepository(),
            new UrlSafetyValidator([], static fn (string $host): array => ['93.184.216.34']),
            discoveryGuard: new DiscoveryGuard($budget, platformPolicy: PlatformPolicy::All),
        );

        $fetcher->fetchForAllowedHosts('https://recovered.example/.well-known/ucp', []);

        self::assertNull($budget->retryAfter('https://recovered.example/.well-known/ucp'));
    }

    /**
     * The profile URI is a storage key with a 500-character column behind it, and a non-ASCII
     * byte is not a URI: both are refused before anything is fetched or keyed on them.
     */
    #[Test]
    public function itRejectsAnOverlongOrNonAsciiProfileUri(): void
    {
        $client = new RecordingHttpClient(new RecordingResponse(500), []);
        $fetcher = new HttpAgentProfileFetcher(
            $client,
            new NullPlatformProfileCacheRepository(),
            new UrlSafetyValidator([], static fn (string $host): array => ['93.184.216.34']),
            discoveryGuard: new DiscoveryGuard(platformPolicy: PlatformPolicy::All),
        );

        try {
            $fetcher->fetch('https://platform.example/' . str_repeat('a', 500));
            self::fail('An overlong URI must be refused.');
        } catch (ValidationException $exception) {
            self::assertSame('Profile URI must be at most 500 ASCII characters.', $exception->getMessage());
        }

        try {
            $fetcher->fetch("https://platform.example/?x=\xff");
            self::fail('A non-ASCII URI must be refused.');
        } catch (ValidationException) {
        }
        self::assertSame(0, $client->requests);
    }

    /**
     * While the budget says no, nothing goes on the wire. A platform whose cached profile went
     * stale is served the stale copy, both while its URL is in backoff and while the window is
     * full, and the client is never called.
     */
    #[Test]
    public function itServesAStaleProfileInsteadOfDeferringABackedOffUrl(): void
    {
        $stale = new PlatformProfile('2026-04-08', [], [], [], []);
        $budget = new InMemoryDiscoveryBudgetRepository();
        $budget->recordFailure('https://known.example/.well-known/ucp', time() + 600);
        $client = new RecordingHttpClient(new RecordingResponse(500), []);
        $fetcher = new HttpAgentProfileFetcher(
            $client,
            new RecordingPlatformProfileCacheRepository(null, $stale),
            new UrlSafetyValidator([], static fn (string $host): array => ['93.184.216.34']),
            discoveryGuard: new DiscoveryGuard($budget, new DiscoveryBudget(maxFetchesPerWindow: 0), PlatformPolicy::All),
        );

        $profile = $fetcher->fetchForAllowedHosts('https://known.example/.well-known/ucp', []);

        self::assertSame($stale, $profile);
        self::assertSame(0, $client->requests, 'a deferred fetch never reaches the network, stale copy or not');
    }

    #[Test]
    public function itServesAStaleProfileInsteadOfFetchingWhenTheWindowIsFull(): void
    {
        $stale = new PlatformProfile('2026-04-08', [], [], [], []);
        $client = new RecordingHttpClient(new RecordingResponse(500), []);
        $fetcher = new HttpAgentProfileFetcher(
            $client,
            new RecordingPlatformProfileCacheRepository(null, $stale),
            new UrlSafetyValidator([], static fn (string $host): array => ['93.184.216.34']),
            discoveryGuard: new DiscoveryGuard(new InMemoryDiscoveryBudgetRepository(), new DiscoveryBudget(maxFetchesPerWindow: 0), PlatformPolicy::All),
        );

        $profile = $fetcher->fetchForAllowedHosts('https://known.example/.well-known/ucp', []);

        self::assertSame($stale, $profile);
        self::assertSame(0, $client->requests, 'a full window serves the stale copy without a fetch');
    }

    /**
     * Keyed on the host, one request naming a path that 404s would lock the platform's real
     * profile out. Keyed on the URL, the bad path backs off and the real profile stays reachable.
     */
    #[Test]
    public function itBacksOffTheFailingUrlAndNotThePlatformsRealProfile(): void
    {
        $body = '{"ucp":{"version":"2026-04-08","services":{},"capabilities":{},"payment_handlers":{}},"signing_keys":[]}';
        $budget = new InMemoryDiscoveryBudgetRepository();
        $guard = new DiscoveryGuard($budget, platformPolicy: PlatformPolicy::All);
        $validator = new UrlSafetyValidator([], static fn (string $host): array => ['93.184.216.34']);

        $attacker = new HttpAgentProfileFetcher(
            new RecordingHttpClient(new RecordingResponse(404), []),
            new NullPlatformProfileCacheRepository(),
            $validator,
            discoveryGuard: $guard,
        );
        try {
            $attacker->fetchForAllowedHosts('https://platform.example/does-not-exist.json', []);
            self::fail('A 404 must fail the fetch.');
        } catch (AgentProfileException) {
        }
        self::assertNotNull($budget->retryAfter('https://platform.example/does-not-exist.json'), 'the bad path is in backoff');

        $client = new RecordingHttpClient(
            new RecordingResponse(200, ['content-length' => [(string) strlen($body)]]),
            [new RecordingChunk(content: $body), new RecordingChunk()],
        );
        $fetcher = new HttpAgentProfileFetcher($client, new NullPlatformProfileCacheRepository(), $validator, discoveryGuard: $guard);

        $profile = $fetcher->fetchForAllowedHosts('https://platform.example/.well-known/ucp', []);

        self::assertInstanceOf(PlatformProfile::class, $profile);
        self::assertSame(1, $client->requests, 'the real profile on the same host is still fetched');
    }

    /**
     * Under `Listed` nothing is discovery: an unlisted host is refused by the validator, and a
     * listed one is never counted, so the budget cannot start answering 503 under the default.
     */
    #[Test]
    public function itNeverDefersUnderTheListedPolicy(): void
    {
        $client = new RecordingHttpClient(new RecordingResponse(500), []);
        $budget = new InMemoryDiscoveryBudgetRepository();
        $fetcher = new HttpAgentProfileFetcher(
            $client,
            new NullPlatformProfileCacheRepository(),
            new UrlSafetyValidator(['listed.example'], static fn (string $host): array => ['93.184.216.34']),
            discoveryGuard: new DiscoveryGuard($budget, new DiscoveryBudget(maxFetchesPerWindow: 0, backoffBaseSeconds: 30)),
        );

        foreach ([1, 2] as $attempt) {
            try {
                $fetcher->fetch('https://listed.example/.well-known/ucp');
                self::fail('A 500 from the platform must fail the fetch.');
            } catch (AgentProfileException $exception) {
                self::assertSame('agent_profile_unavailable', $exception->errorCode, "attempt {$attempt} is a plain failure, not a deferral");
            }
        }
        self::assertNull($budget->retryAfter('https://listed.example/.well-known/ucp'));
    }

    /**
     * The policy of the request's runtime configuration wins over the fetcher's default, so a
     * host that resolves the policy per tenant gets consistent gates in the factory and here.
     */
    #[Test]
    public function itHonoursThePerCallPlatformPolicy(): void
    {
        $body = '{"ucp":{"version":"2026-04-08","services":{},"capabilities":{},"payment_handlers":{}},"signing_keys":[]}';
        $client = new RecordingHttpClient(
            new RecordingResponse(200, ['content-length' => [(string) strlen($body)]]),
            [new RecordingChunk(content: $body), new RecordingChunk()],
        );
        $fetcher = new HttpAgentProfileFetcher(
            $client,
            new NullPlatformProfileCacheRepository(),
            new UrlSafetyValidator([], static fn (string $host): array => ['93.184.216.34']),
        );

        $profile = $fetcher->fetchForPolicy('https://unlisted.example/.well-known/ucp', [], PlatformPolicy::All);

        self::assertInstanceOf(PlatformProfile::class, $profile);
        self::assertSame(1, $client->requests);
    }
}

final class RecordingRevalidatingCacheRepository implements RevalidatingPlatformProfileCacheRepositoryInterface
{
    /** @var list<array{uri: string, profile: PlatformProfile, expiresAt: int, etag: ?string}> */
    public array $savedEntries = [];

    public function __construct(
        private ?CachedPlatformProfile $entry = null,
    ) {
    }

    public function findEntry(string $uri): ?CachedPlatformProfile
    {
        return $this->entry;
    }

    public function saveEntry(string $uri, PlatformProfile $profile, int $expiresAt, ?string $etag = null): void
    {
        $this->entry = new CachedPlatformProfile($profile, $expiresAt, $etag);
        $this->savedEntries[] = ['uri' => $uri, 'profile' => $profile, 'expiresAt' => $expiresAt, 'etag' => $etag];
    }

    public function save(string $uri, PlatformProfile $profile): void
    {
        throw new \LogicException('The fetcher must use saveEntry() on a revalidating repository.');
    }

    public function find(string $uri, bool $allowExpired = false): ?PlatformProfile
    {
        throw new \LogicException('The fetcher must use findEntry() on a revalidating repository.');
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

/** A cache that holds nothing, so every fetch is a cold fetch. */
final class NullPlatformProfileCacheRepository implements PlatformProfileCacheRepositoryInterface
{
    public function save(string $uri, PlatformProfile $profile): void
    {
    }

    public function find(string $uri, bool $allowExpired = false): ?PlatformProfile
    {
        return null;
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
    public int $requests = 0;

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
        ++$this->requests;
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
