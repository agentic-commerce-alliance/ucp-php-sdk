<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Enum\SignaturePolicy;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Internal\Service\DefaultHttpRequestContextFactory;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Negotiation\NegotiatedCapabilities;
use Ucp\Sdk\Model\Negotiation\NegotiationSession;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Security\MerchantAuthorizationVerificationResult;
use Ucp\Sdk\Model\Security\PublicSigningKey;
use Ucp\Sdk\Model\Security\SignatureVerificationResult;
use Ucp\Sdk\Repository\NegotiationSessionRepositoryInterface;
use Ucp\Sdk\Service\AgentProfileFetcherInterface;
use Ucp\Sdk\Service\CapabilityNegotiatorInterface;
use Ucp\Sdk\Service\MerchantAuthorizationServiceInterface;
use Ucp\Sdk\Service\RequestSignatureServiceInterface;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;

final class DefaultHttpRequestContextFactoryTest extends TestCase
{
    #[Test]
    public function itBuildsANegotiatedRequestContextAndStoresTheSession(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('platform-key');
        $profile = new PlatformProfile(
            '2026-04-08',
            [],
            [
                'dev.ucp.shopping.checkout' => [
                    new CapabilityDescriptor('dev.ucp.shopping.checkout', '2026-04-08', 'https://example.test/spec', 'https://example.test/schema'),
                ],
            ],
            [],
            [$manager->toPublicKey($managedKey)],
        );
        $state = new SavedSessionState();

        $factory = new DefaultHttpRequestContextFactory(
            new class () implements RuntimeConfigurationResolverInterface {
                public function resolve(HttpRequest $request): RuntimeConfiguration
                {
                    return new RuntimeConfiguration(
                        '2026-04-08',
                        'https://merchant.example',
                        SignaturePolicy::Strict,
                        false,
                        ['platform.example'],
                        ['platform.example'],
                        ['2026-04-08' => 'https://merchant.example/.well-known/ucp'],
                        enabledCapabilities: [],
                        tenantIdentifier: 'tenant-a',
                    );
                }
            },
            new class ($profile) implements AgentProfileFetcherInterface {
                public function __construct(private readonly PlatformProfile $profile)
                {
                }

                public function fetch(string $uri): PlatformProfile
                {
                    return $this->profile;
                }
            },
            new class () implements RequestSignatureServiceInterface {
                public function sign(HttpRequest $request, \Ucp\Sdk\Model\Security\ManagedSigningKey $key, ?int $created = null, ?int $expires = null): array
                {
                    return [];
                }

                public function verify(HttpRequest $request, array $keys): SignatureVerificationResult
                {
                    return new SignatureVerificationResult(true, 'platform-key', 'ES256', 1_700_000_000, 1_700_000_120, true, true);
                }
            },
            new class () implements CapabilityNegotiatorInterface {
                public function negotiate(?PlatformProfile $platformProfile, \Ucp\Sdk\Model\RequestContext $context): NegotiatedCapabilities
                {
                    return new NegotiatedCapabilities([
                        'dev.ucp.shopping.checkout' => [
                            new CapabilityDescriptor('dev.ucp.shopping.checkout', '2026-04-08', 'https://example.test/spec', 'https://example.test/schema'),
                        ],
                    ], ['handler-demo'], ['checkout.create' => ['dev.ucp.shopping.checkout']]);
                }
            },
            new class ($state) implements NegotiationSessionRepositoryInterface {
                public function __construct(private SavedSessionState $state)
                {
                }

                public function save(NegotiationSession $session): void
                {
                    $this->state->savedSession = $session;
                }

                public function find(string $id): ?NegotiationSession
                {
                    return null;
                }

                public function findByProfileUri(string $platformProfileUri, ?string $tenantIdentifier = null): ?NegotiationSession
                {
                    return null;
                }

                public function purgeExpired(int $olderThanUnixTimestamp): void
                {
                }
            },
        );

        $request = new HttpRequest('POST', 'https://merchant.example/ucp/v1/checkout-sessions', [
            'UCP-Agent' => 'platform; profile="https://platform.example/.well-known/ucp"',
            'Idempotency-Key' => 'idem-1',
        ], [], '{"ok":true}');

        $context = $factory->create($request);

        self::assertSame('platform.example', parse_url((string) $context->platformProfileUri, PHP_URL_HOST));
        self::assertTrue($context->signatureVerified);
        self::assertSame(['dev.ucp.shopping.checkout'], $context->negotiatedCapabilities);
        self::assertNotNull($context->negotiation);
        self::assertSame(['handler-demo'], $context->negotiation->paymentHandlerIds);
        self::assertInstanceOf(NegotiationSession::class, $state->savedSession);
        self::assertSame('neg_' . substr(hash('sha256', 'https://platform.example/.well-known/ucp|tenant-a'), 0, 16), $context->negotiationSessionId);
        self::assertSame('tenant-a', $state->savedSession->tenantIdentifier);
        self::assertSame($context->negotiationSessionId, $state->savedSession->id);
    }

    #[Test]
    public function itBuildsAContextWithoutFetchingAProfileWhenNoAgentHeaderIsPresent(): void
    {
        $state = new NoProfileState();
        $factory = new DefaultHttpRequestContextFactory(
            new class () implements RuntimeConfigurationResolverInterface {
                public function resolve(HttpRequest $request): RuntimeConfiguration
                {
                    return new RuntimeConfiguration('2026-04-08', 'https://merchant.example', SignaturePolicy::Log);
                }
            },
            new class ($state) implements AgentProfileFetcherInterface {
                public function __construct(private NoProfileState $state)
                {
                }

                public function fetch(string $uri): PlatformProfile
                {
                    ++$this->state->fetchCalls;

                    throw new \RuntimeException('Fetcher should not be called without a profile header.');
                }
            },
            new class ($state) implements RequestSignatureServiceInterface {
                public function __construct(private NoProfileState $state)
                {
                }

                public function sign(HttpRequest $request, \Ucp\Sdk\Model\Security\ManagedSigningKey $key, ?int $created = null, ?int $expires = null): array
                {
                    return [];
                }

                public function verify(HttpRequest $request, array $keys): SignatureVerificationResult
                {
                    ++$this->state->verifyCalls;

                    return new SignatureVerificationResult(false);
                }
            },
            new class ($state) implements CapabilityNegotiatorInterface {
                public function __construct(private NoProfileState $state)
                {
                }

                public function negotiate(?PlatformProfile $platformProfile, \Ucp\Sdk\Model\RequestContext $context): NegotiatedCapabilities
                {
                    $this->state->negotiatedProfile = $platformProfile;

                    return new NegotiatedCapabilities();
                }
            },
        );

        $context = $factory->create(new HttpRequest('GET', 'https://merchant.example/ucp/v1/catalog', [
            'Idempotency-Key' => 'idem-42',
            'X-OAuth-Client-Id' => 'client-7',
            'X-Custom-Header' => 'yes',
        ]));

        self::assertSame('merchant.example', $context->host);
        self::assertSame('idem-42', $context->idempotencyKey);
        self::assertSame('client-7', $context->oauthClientId);
        self::assertSame('yes', $context->headers['x-custom-header']);
        self::assertNull($context->platformProfileUri);
        self::assertNull($context->platformProfile);
        self::assertFalse($context->signatureVerified);
        self::assertSame([], $context->negotiatedCapabilities);
        self::assertNull($context->negotiationSessionId);
        self::assertSame(0, $state->fetchCalls);
        self::assertSame(0, $state->verifyCalls);
        self::assertNull($state->negotiatedProfile);
    }

    #[Test]
    public function itRejectsStrictRequestsWithoutVerifiedSignatures(): void
    {
        $profile = new PlatformProfile('2026-04-08', [], [], [], []);
        $factory = new DefaultHttpRequestContextFactory(
            new class () implements RuntimeConfigurationResolverInterface {
                public function resolve(HttpRequest $request): RuntimeConfiguration
                {
                    return new RuntimeConfiguration(
                        '2026-04-08',
                        'https://merchant.example',
                        SignaturePolicy::Strict,
                        false,
                        ['platform.example'],
                        ['platform.example'],
                    );
                }
            },
            new class ($profile) implements AgentProfileFetcherInterface {
                public function __construct(private readonly PlatformProfile $profile)
                {
                }

                public function fetch(string $uri): PlatformProfile
                {
                    return $this->profile;
                }
            },
            new class () implements RequestSignatureServiceInterface {
                public function sign(HttpRequest $request, \Ucp\Sdk\Model\Security\ManagedSigningKey $key, ?int $created = null, ?int $expires = null): array
                {
                    return [];
                }

                public function verify(HttpRequest $request, array $keys): SignatureVerificationResult
                {
                    return new SignatureVerificationResult(false, failureReason: 'bad signature');
                }
            },
            new class () implements CapabilityNegotiatorInterface {
                public function negotiate(?PlatformProfile $platformProfile, \Ucp\Sdk\Model\RequestContext $context): NegotiatedCapabilities
                {
                    return new NegotiatedCapabilities();
                }
            },
        );

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('bad signature');

        $factory->create(new HttpRequest('GET', 'https://merchant.example/.well-known/ucp', [
            'UCP-Agent' => 'platform; profile="https://platform.example/.well-known/ucp"',
        ]));
    }

    #[Test]
    public function itVerifiesMerchantAuthorizationAgainstTheResolvedPublicKeys(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('platform-key-auth');
        $profile = new PlatformProfile('2026-04-08', [], [], [], [$manager->toPublicKey($managedKey)]);
        $state = new MerchantAuthorizationState();

        $factory = new DefaultHttpRequestContextFactory(
            new class () implements RuntimeConfigurationResolverInterface {
                public function resolve(HttpRequest $request): RuntimeConfiguration
                {
                    return new RuntimeConfiguration(
                        '2026-04-08',
                        'https://merchant.example',
                        SignaturePolicy::Log,
                        false,
                        ['platform.example'],
                        ['platform.example'],
                    );
                }
            },
            new class ($profile) implements AgentProfileFetcherInterface {
                public function __construct(private readonly PlatformProfile $profile)
                {
                }

                public function fetch(string $uri): PlatformProfile
                {
                    return $this->profile;
                }
            },
            new class () implements RequestSignatureServiceInterface {
                public function sign(HttpRequest $request, \Ucp\Sdk\Model\Security\ManagedSigningKey $key, ?int $created = null, ?int $expires = null): array
                {
                    return [];
                }

                public function verify(HttpRequest $request, array $keys): SignatureVerificationResult
                {
                    return new SignatureVerificationResult(true, 'platform-key-auth', 'ES256');
                }
            },
            new class () implements CapabilityNegotiatorInterface {
                public function negotiate(?PlatformProfile $platformProfile, \Ucp\Sdk\Model\RequestContext $context): NegotiatedCapabilities
                {
                    return new NegotiatedCapabilities();
                }
            },
            null,
            new class ($state) implements MerchantAuthorizationServiceInterface {
                public function __construct(private MerchantAuthorizationState $state)
                {
                }

                public function verify(HttpRequest $request, array $keys, \Ucp\Sdk\Model\RequestContext $context): MerchantAuthorizationVerificationResult
                {
                    $this->state->keys = $keys;
                    $this->state->context = $context;

                    return new MerchantAuthorizationVerificationResult(true, 'merchant-auth');
                }
            },
        );

        $context = $factory->create(new HttpRequest('GET', 'https://merchant.example/.well-known/ucp', [
            'UCP-Agent' => 'platform; profile="https://platform.example/.well-known/ucp"',
        ]));

        self::assertCount(1, $state->keys);
        self::assertNotNull($state->context);
        self::assertSame('https://platform.example/.well-known/ucp', $state->context->platformProfileUri);
        self::assertTrue($context->merchantAuthorizationVerification->verified);
    }

    #[Test]
    public function itRejectsDisallowedPlatformProfileHosts(): void
    {
        $factory = new DefaultHttpRequestContextFactory(
            new class () implements RuntimeConfigurationResolverInterface {
                public function resolve(HttpRequest $request): RuntimeConfiguration
                {
                    return new RuntimeConfiguration(
                        '2026-04-08',
                        'https://merchant.example',
                        SignaturePolicy::Log,
                        false,
                        ['trusted.example'],
                        ['trusted.example'],
                    );
                }
            },
            new class () implements AgentProfileFetcherInterface {
                public function fetch(string $uri): PlatformProfile
                {
                    throw new \RuntimeException('This fetcher should not be called for a blocked host.');
                }
            },
            new class () implements RequestSignatureServiceInterface {
                public function sign(HttpRequest $request, \Ucp\Sdk\Model\Security\ManagedSigningKey $key, ?int $created = null, ?int $expires = null): array
                {
                    return [];
                }

                public function verify(HttpRequest $request, array $keys): SignatureVerificationResult
                {
                    return new SignatureVerificationResult(false);
                }
            },
            new class () implements CapabilityNegotiatorInterface {
                public function negotiate(?PlatformProfile $platformProfile, \Ucp\Sdk\Model\RequestContext $context): NegotiatedCapabilities
                {
                    return new NegotiatedCapabilities();
                }
            },
        );

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Platform profile host is not allowed by the current runtime configuration.');

        $factory->create(new HttpRequest('GET', 'https://merchant.example/.well-known/ucp', [
            'UCP-Agent' => 'platform; profile="https://bad.example/.well-known/ucp"',
        ]));
    }
}

final class SavedSessionState
{
    public ?NegotiationSession $savedSession = null;
}

final class NoProfileState
{
    public int $fetchCalls = 0;
    public int $verifyCalls = 0;
    public ?PlatformProfile $negotiatedProfile = null;
}

final class MerchantAuthorizationState
{
    /** @var list<PublicSigningKey> */
    public array $keys = [];

    public ?\Ucp\Sdk\Model\RequestContext $context = null;
}
