<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Enum\SignaturePolicy;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Internal\Service\DefaultHttpRequestContextFactory;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Negotiation\NegotiatedCapabilities;
use Ucp\Sdk\Model\Negotiation\NegotiationSession;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;
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
    private RuntimeConfiguration $runtimeConfiguration;

    private PlatformProfile $platformProfile;

    private SignatureVerificationResult $signatureVerificationResult;

    private NegotiatedCapabilities $negotiatedCapabilities;

    private ?NegotiationSession $savedNegotiationSession = null;

    private ?string $fetchedProfileUri = null;

    private int $profileFetches = 0;

    private int $signatureVerifications = 0;

    private int $negotiations = 0;

    private ?PlatformProfile $negotiatedProfile = null;

    /** @var list<PublicSigningKey> */
    private array $merchantAuthorizationKeys = [];

    private ?RequestContext $merchantAuthorizationContext = null;

    private DefaultHttpRequestContextFactory $factory;

    private DefaultHttpRequestContextFactory $factoryWithMerchantAuthorization;

    protected function setUp(): void
    {
        $this->runtimeConfiguration = new RuntimeConfiguration('2026-04-08', 'https://merchant.example', SignaturePolicy::Log);
        $this->platformProfile = new PlatformProfile('2026-04-08', [], [], [], []);
        $this->signatureVerificationResult = new SignatureVerificationResult(false);
        $this->negotiatedCapabilities = new NegotiatedCapabilities();

        $runtimeConfigurationResolver = $this->createMock(RuntimeConfigurationResolverInterface::class);
        $runtimeConfigurationResolver
            ->method('resolve')
            ->willReturnCallback(fn (HttpRequest $request): RuntimeConfiguration => $this->runtimeConfiguration);
        $agentProfileFetcher = $this->createMock(AgentProfileFetcherInterface::class);
        $agentProfileFetcher
            ->method('fetch')
            ->willReturnCallback(function (string $uri): PlatformProfile {
                ++$this->profileFetches;
                $this->fetchedProfileUri = $uri;

                return $this->platformProfile;
            });
        $requestSignatureService = $this->createMock(RequestSignatureServiceInterface::class);
        $requestSignatureService
            ->method('verify')
            ->willReturnCallback(function (HttpRequest $request, array $keys): SignatureVerificationResult {
                ++$this->signatureVerifications;

                return $this->signatureVerificationResult;
            });
        $capabilityNegotiator = $this->createMock(CapabilityNegotiatorInterface::class);
        $capabilityNegotiator
            ->method('negotiate')
            ->willReturnCallback(function (?PlatformProfile $platformProfile, RequestContext $context): NegotiatedCapabilities {
                ++$this->negotiations;
                $this->negotiatedProfile = $platformProfile;

                return $this->negotiatedCapabilities;
            });
        $negotiationSessionRepository = $this->createMock(NegotiationSessionRepositoryInterface::class);
        $negotiationSessionRepository
            ->method('findByProfileUri')
            ->willReturn(null);
        $negotiationSessionRepository
            ->method('save')
            ->willReturnCallback(function (NegotiationSession $session): void {
                $this->savedNegotiationSession = $session;
            });
        $merchantAuthorizationService = $this->createMock(MerchantAuthorizationServiceInterface::class);
        $merchantAuthorizationService
            ->method('verify')
            ->willReturnCallback(function (HttpRequest $request, array $keys, RequestContext $context): MerchantAuthorizationVerificationResult {
                /** @var list<PublicSigningKey> $keys */
                $this->merchantAuthorizationKeys = $keys;
                $this->merchantAuthorizationContext = $context;

                return new MerchantAuthorizationVerificationResult(true, 'merchant-auth');
            });

        $this->factory = new DefaultHttpRequestContextFactory(
            $runtimeConfigurationResolver,
            $agentProfileFetcher,
            $requestSignatureService,
            $capabilityNegotiator,
            $negotiationSessionRepository,
        );
        $this->factoryWithMerchantAuthorization = new DefaultHttpRequestContextFactory(
            $runtimeConfigurationResolver,
            $agentProfileFetcher,
            $requestSignatureService,
            $capabilityNegotiator,
            $negotiationSessionRepository,
            $merchantAuthorizationService,
        );
    }

    #[Test]
    public function itBuildsANegotiatedRequestContextAndStoresTheSession(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('platform-key');
        $this->platformProfile = new PlatformProfile(
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
        $this->runtimeConfiguration = new RuntimeConfiguration(
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
        $this->signatureVerificationResult = new SignatureVerificationResult(true, 'platform-key', 'ES256', 1_700_000_000, 1_700_000_120, true, true);
        $this->negotiatedCapabilities = new NegotiatedCapabilities([
            'dev.ucp.shopping.checkout' => [
                new CapabilityDescriptor('dev.ucp.shopping.checkout', '2026-04-08', 'https://example.test/spec', 'https://example.test/schema'),
            ],
        ], ['handler-demo'], ['checkout.create' => ['dev.ucp.shopping.checkout']]);

        $request = new HttpRequest('POST', 'https://merchant.example/ucp/v1/checkout-sessions', [
            'UCP-Agent' => 'platform; profile="https://platform.example/.well-known/ucp"',
            'Idempotency-Key' => 'idem-1',
        ], [], '{"ok":true}');

        $context = $this->factory->create($request);

        self::assertSame('platform.example', parse_url((string) $context->platformProfileUri, PHP_URL_HOST));
        self::assertTrue($context->signatureVerified);
        self::assertSame(['dev.ucp.shopping.checkout'], $context->negotiatedCapabilities);
        self::assertNotNull($context->negotiation);
        self::assertSame(['handler-demo'], $context->negotiation->paymentHandlerIds);
        self::assertSame('https://platform.example/.well-known/ucp', $this->fetchedProfileUri);
        self::assertSame(1, $this->profileFetches);
        self::assertSame(1, $this->signatureVerifications);
        self::assertSame(1, $this->negotiations);
        self::assertInstanceOf(NegotiationSession::class, $this->savedNegotiationSession);
        self::assertSame('neg_' . substr(hash('sha256', 'https://platform.example/.well-known/ucp|tenant-a'), 0, 16), $context->negotiationSessionId);
        self::assertSame('tenant-a', $this->savedNegotiationSession->tenantIdentifier);
        self::assertSame($context->negotiationSessionId, $this->savedNegotiationSession->id);
    }

    #[Test]
    public function itRejectsRuntimeRequestsWithoutAnAgentProfileHeader(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('UCP-Agent header with a profile URI is required for UCP runtime requests.');

        try {
            $this->factory->create(new HttpRequest('GET', 'https://merchant.example/ucp/v1/catalog', [
                'Idempotency-Key' => 'idem-42',
                'X-OAuth-Client-Id' => 'client-7',
                'X-Custom-Header' => 'yes',
            ]));
        } finally {
            self::assertSame(0, $this->profileFetches);
            self::assertSame(0, $this->signatureVerifications);
            self::assertSame(0, $this->negotiations);
            self::assertNull($this->negotiatedProfile);
        }
    }

    #[Test]
    public function itRejectsRuntimeRequestsWithAMalformedAgentProfileHeader(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('UCP-Agent header must include a non-empty profile URI.');

        try {
            $this->factory->create(new HttpRequest('GET', 'https://merchant.example/ucp/v1/catalog', [
                'UCP-Agent' => 'platform',
                'Idempotency-Key' => 'idem-42',
                'X-OAuth-Client-Id' => 'client-7',
                'X-Custom-Header' => 'yes',
            ]));
        } finally {
            self::assertSame(0, $this->profileFetches);
            self::assertSame(0, $this->signatureVerifications);
            self::assertSame(0, $this->negotiations);
            self::assertNull($this->negotiatedProfile);
        }
    }

    #[Test]
    public function itRejectsRuntimeRequestsWithAnEmptyAgentProfileUri(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('UCP-Agent header must include a non-empty profile URI.');

        try {
            $this->factory->create(new HttpRequest('GET', 'https://merchant.example/ucp/v1/catalog', [
                'UCP-Agent' => 'platform; profile=""',
                'Idempotency-Key' => 'idem-42',
                'X-OAuth-Client-Id' => 'client-7',
                'X-Custom-Header' => 'yes',
            ]));
        } finally {
            self::assertSame(0, $this->profileFetches);
            self::assertSame(0, $this->signatureVerifications);
            self::assertSame(0, $this->negotiations);
            self::assertNull($this->negotiatedProfile);
        }
    }

    #[Test]
    public function itRejectsStrictRequestsWithoutVerifiedSignatures(): void
    {
        $this->runtimeConfiguration = new RuntimeConfiguration(
            '2026-04-08',
            'https://merchant.example',
            SignaturePolicy::Strict,
            false,
            ['platform.example'],
            ['platform.example'],
        );
        $this->signatureVerificationResult = new SignatureVerificationResult(false, failureReason: 'bad signature');

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('bad signature');

        $this->factory->create(new HttpRequest('GET', 'https://merchant.example/.well-known/ucp', [
            'UCP-Agent' => 'platform; profile="https://platform.example/.well-known/ucp"',
        ]));
    }

    #[Test]
    public function itRejectsUntrustedProfileHostsBeforeFetchingTheProfile(): void
    {
        $runtimeConfigurationResolver = $this->createMock(RuntimeConfigurationResolverInterface::class);
        $runtimeConfigurationResolver
            ->expects($this->once())
            ->method('resolve')
            ->willReturn(new RuntimeConfiguration('2026-04-08', 'https://merchant.example', SignaturePolicy::Strict));

        $agentProfileFetcher = $this->createMock(AgentProfileFetcherInterface::class);
        $agentProfileFetcher
            ->expects($this->never())
            ->method('fetch');

        $requestSignatureService = $this->createMock(RequestSignatureServiceInterface::class);
        $requestSignatureService
            ->expects($this->never())
            ->method('verify');

        $capabilityNegotiator = $this->createMock(CapabilityNegotiatorInterface::class);
        $capabilityNegotiator
            ->expects($this->never())
            ->method('negotiate');

        $factory = new DefaultHttpRequestContextFactory(
            $runtimeConfigurationResolver,
            $agentProfileFetcher,
            $requestSignatureService,
            $capabilityNegotiator,
        );

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Platform profile host is not allowed by the current runtime configuration.');

        $factory->create(new HttpRequest('GET', 'https://merchant.example/.well-known/ucp', [
            'UCP-Agent' => 'platform; profile="https://public.example/.well-known/ucp"',
        ]));
    }

    #[Test]
    public function itVerifiesMerchantAuthorizationAgainstTheResolvedPublicKeys(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('platform-key-auth');
        $this->platformProfile = new PlatformProfile('2026-04-08', [], [], [], [$manager->toPublicKey($managedKey)]);
        $this->runtimeConfiguration = new RuntimeConfiguration(
            '2026-04-08',
            'https://merchant.example',
            SignaturePolicy::Log,
            false,
            ['platform.example'],
            ['platform.example'],
        );
        $this->signatureVerificationResult = new SignatureVerificationResult(true, 'platform-key-auth', 'ES256');

        $context = $this->factoryWithMerchantAuthorization->create(new HttpRequest('GET', 'https://merchant.example/.well-known/ucp', [
            'UCP-Agent' => 'platform; profile="https://platform.example/.well-known/ucp"',
        ]));

        self::assertCount(1, $this->merchantAuthorizationKeys);
        self::assertNotNull($this->merchantAuthorizationContext);
        self::assertSame('https://platform.example/.well-known/ucp', $this->merchantAuthorizationContext->platformProfileUri);
        self::assertTrue($context->merchantAuthorizationVerification->verified);
    }

    #[Test]
    public function itRejectsDisallowedPlatformProfileHosts(): void
    {
        $this->runtimeConfiguration = new RuntimeConfiguration(
            '2026-04-08',
            'https://merchant.example',
            SignaturePolicy::Log,
            false,
            ['trusted.example'],
            ['trusted.example'],
        );

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Platform profile host is not allowed by the current runtime configuration.');

        $this->factory->create(new HttpRequest('GET', 'https://merchant.example/.well-known/ucp', [
            'UCP-Agent' => 'platform; profile="https://bad.example/.well-known/ucp"',
        ]));
    }
}
