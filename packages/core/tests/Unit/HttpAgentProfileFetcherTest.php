<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Ucp\Sdk\Internal\Http\HttpAgentProfileFetcher;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Repository\PlatformProfileCacheRepositoryInterface;

final class HttpAgentProfileFetcherTest extends TestCase
{
    #[Test]
    public function itReturnsAStaleCachedProfileWhenTheRemoteFetchFails(): void
    {
        $freshProfile = null;
        $staleProfile = new PlatformProfile('2026-04-08', [], [], [], [], [
            '2026-04-08' => 'https://platform.example/.well-known/ucp',
        ]);

        $fetcher = new HttpAgentProfileFetcher(
            new MockHttpClient([
                new MockResponse('{"error":"nope"}', ['http_code' => 500]),
            ]),
            new class ($freshProfile, $staleProfile) implements PlatformProfileCacheRepositoryInterface {
                public function __construct(
                    private ?PlatformProfile $freshProfile,
                    private ?PlatformProfile $staleProfile,
                ) {
                }

                public function save(string $uri, PlatformProfile $profile): void
                {
                    $this->freshProfile = $profile;
                }

                public function find(string $uri, bool $allowExpired = false): ?PlatformProfile
                {
                    return $allowExpired ? $this->staleProfile : $this->freshProfile;
                }

                public function purgeExpired(int $olderThanUnixTimestamp): void
                {
                }
            },
            new UrlSafetyValidator(
                ['platform.example'],
                static fn (string $host): array => $host === 'platform.example' ? ['203.0.113.10'] : [],
            ),
        );

        $profile = $fetcher->fetch('https://platform.example/.well-known/ucp');

        self::assertSame($staleProfile, $profile);
    }

    #[Test]
    public function itPinsTheValidatedDnsResolutionIntoTheHttpClientRequest(): void
    {
        $responseSeen = new class () {
            /** @var array<string, mixed> */
            public array $options = [];
        };

        $fetcher = new HttpAgentProfileFetcher(
            new MockHttpClient(static function (string $method, string $url, array $options) use ($responseSeen): MockResponse {
                $responseSeen->options = $options;

                return new MockResponse('{"ucp":{"version":"2026-04-08"},"capabilities":[],"payment_handlers":[],"signing_keys":[],"supported_versions":{"2026-04-08":"https://platform.example/.well-known/ucp"}}', ['http_code' => 200]);
            }),
            new class () implements PlatformProfileCacheRepositoryInterface {
                public function save(string $uri, PlatformProfile $profile): void
                {
                }

                public function find(string $uri, bool $allowExpired = false): ?PlatformProfile
                {
                    return null;
                }

                public function purgeExpired(int $olderThanUnixTimestamp): void
                {
                }
            },
            new UrlSafetyValidator(
                ['platform.example'],
                static fn (string $host): array => $host === 'platform.example' ? ['203.0.113.10'] : [],
            ),
        );

        $fetcher->fetch('https://platform.example/.well-known/ucp');

        self::assertSame(['platform.example' => '203.0.113.10'], $responseSeen->options['resolve']);
    }

    #[Test]
    public function itRejectsResponsesThatExceedTheConfiguredByteLimitWhileStreaming(): void
    {
        $fetcher = new HttpAgentProfileFetcher(
            new MockHttpClient([
                new MockResponse(str_repeat('a', 2048), ['http_code' => 200]),
            ]),
            new class () implements PlatformProfileCacheRepositoryInterface {
                public function save(string $uri, PlatformProfile $profile): void
                {
                }

                public function find(string $uri, bool $allowExpired = false): ?PlatformProfile
                {
                    return null;
                }

                public function purgeExpired(int $olderThanUnixTimestamp): void
                {
                }
            },
            new UrlSafetyValidator(
                ['platform.example'],
                static fn (string $host): array => $host === 'platform.example' ? ['203.0.113.10'] : [],
            ),
            maxResponseBytes: 512,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Platform profile response exceeded the maximum allowed size.');

        $fetcher->fetch('https://platform.example/.well-known/ucp');
    }
}
