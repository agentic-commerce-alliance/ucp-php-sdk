<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Enum\SignaturePolicy;
use Ucp\Sdk\Enum\VersionNegotiationOutcome;
use Ucp\Sdk\Event\VersionNegotiationObservedEvent;
use Ucp\Sdk\Exception\NegotiationException;
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
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Security\MerchantAuthorizationVerificationResult;
use Ucp\Sdk\Model\Security\PublicSigningKey;
use Ucp\Sdk\Model\Security\SignatureVerificationResult;
use Ucp\Sdk\Repository\NegotiationSessionRepositoryInterface;
use Ucp\Sdk\Service\AgentProfileFetcherInterface;
use Ucp\Sdk\Service\CapabilityNegotiatorInterface;
use Ucp\Sdk\Service\EventDispatcherInterface;
use Ucp\Sdk\Service\MerchantAuthorizationServiceInterface;
use Ucp\Sdk\Service\ProfileBuilderInterface;
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

    /**
     * A refusal on the header's `version` parameter never reaches the executor, where every
     * other version decision is observed. If it were not observed here, the platforms that
     * announce an older version up front -- the most explicit signal there is -- would be the
     * one group missing from the histogram the single-version decision is revisited on.
     */
    #[Test]
    public function itObservesAVersionRefusedOnTheAgentHeaderBeforeFetchingTheProfile(): void
    {
        $dispatcher = new ObservationRecordingEventDispatcher();
        $factory = $this->factoryWithDispatcher($dispatcher);

        $request = new HttpRequest('POST', 'https://merchant.example/ucp/v1/checkout-sessions', [
            'UCP-Agent' => 'platform; profile="https://platform.example/.well-known/ucp"; version="2026-01-23"',
        ], [], '{}');

        try {
            $factory->create($request);
            self::fail('Expected the declared version to be refused.');
        } catch (NegotiationException $exception) {
            self::assertSame('version_unsupported', $exception->errorCode);
        }

        self::assertCount(1, $dispatcher->events);
        $event = $dispatcher->events[0];
        self::assertInstanceOf(VersionNegotiationObservedEvent::class, $event);
        self::assertSame('2026-01-23', $event->getObservedVersion());
        self::assertSame('2026-04-08', $event->getServedVersion());
        self::assertSame('https://platform.example/.well-known/ucp', $event->getAgentProfileUri());
        self::assertSame(VersionNegotiationOutcome::Rejected, $event->getOutcome());
        self::assertSame(0, $this->profileFetches, 'The refusal must happen before the profile is fetched.');
    }

    /**
     * A header version that matches is not observed here. The request goes on to the
     * executor, which observes the profile's version once; a second observation for the same
     * request would count that platform twice.
     */
    #[Test]
    public function itDoesNotObserveAMatchingHeaderVersionTwice(): void
    {
        $dispatcher = new ObservationRecordingEventDispatcher();
        $factory = $this->factoryWithDispatcher($dispatcher);
        $this->runtimeConfiguration = new RuntimeConfiguration(
            '2026-04-08',
            'https://merchant.example',
            SignaturePolicy::Log,
            allowedProfileHosts: ['platform.example'],
        );

        $factory->create(new HttpRequest('POST', 'https://merchant.example/ucp/v1/checkout-sessions', [
            'UCP-Agent' => 'platform; profile="https://platform.example/.well-known/ucp"; version="2026-04-08"',
        ], [], '{}'));

        self::assertSame([], $dispatcher->events);
        self::assertSame(1, $this->profileFetches);
    }

    /**
     * The first request against a fresh install used to need a second web server, because the
     * agent profile has to come from somewhere and nothing a laptop can offer passes the SSRF
     * rules. In development mode the business's own discovery document is that profile: built
     * in-process, not fetched, so the fetcher is never asked.
     */
    #[Test]
    public function itNegotiatesAgainstItsOwnProfileInDevelopmentMode(): void
    {
        $builder = new RecordingProfileBuilder(new PlatformProfile('2026-04-08', [], [
            'dev.ucp.shopping.checkout' => [
                new CapabilityDescriptor('dev.ucp.shopping.checkout', '2026-04-08', 'https://merchant.example/spec', 'https://merchant.example/schema'),
            ],
        ], []));
        $factory = $this->factoryWithProfileBuilder($builder);
        $this->runtimeConfiguration = new RuntimeConfiguration(
            '2026-04-08',
            'https://merchant.example',
            SignaturePolicy::Log,
            enabledCapabilities: ['dev.ucp.shopping.checkout'],
            tenantIdentifier: 'tenant-a',
            profileFetchingDevelopmentMode: true,
        );

        $context = $factory->create(new HttpRequest('POST', 'https://merchant.example/ucp/v1/checkout-sessions', [
            'UCP-Agent' => 'dev-console; profile="https://merchant.example/.well-known/ucp"',
        ], [], '{}'));

        self::assertSame(0, $this->profileFetches, 'The own profile is built, never fetched.');
        self::assertSame($builder->profile, $context->platformProfile);
        self::assertSame($builder->profile, $this->negotiatedProfile);
        self::assertNotNull($builder->input);
        self::assertSame('2026-04-08', $builder->input->version);
        self::assertSame('https://merchant.example', $builder->input->baseUri);
        self::assertSame('tenant-a', $builder->input->tenantIdentifier);
        self::assertSame(['dev.ucp.shopping.checkout'], $builder->input->enabledCapabilities);
    }

    #[Test]
    public function itAcceptsItsOwnProfileByTheRequestOriginWhenNoBaseUriIsConfigured(): void
    {
        $builder = new RecordingProfileBuilder(new PlatformProfile('2026-04-08', [], [], []));
        $factory = $this->factoryWithProfileBuilder($builder);
        $this->runtimeConfiguration = new RuntimeConfiguration('2026-04-08', '', SignaturePolicy::Log, profileFetchingDevelopmentMode: true);

        $factory->create(new HttpRequest('POST', 'http://shop.localhost:8088/ucp/v1/carts', [
            'UCP-Agent' => 'dev-console; profile="http://shop.localhost:8088/.well-known/ucp"',
        ], [], '{}'));

        self::assertSame(0, $this->profileFetches);
        self::assertNotNull($builder->input);
        self::assertSame('http://shop.localhost:8088', $builder->input->baseUri, 'The base URI is derived from the profile URI when none is configured.');
    }

    /**
     * Outside development mode the own profile is just another URL, and takes the full path:
     * with an empty allowlist that means refusal. Development mode is the whole permission.
     */
    #[Test]
    public function itDoesNotTreatItsOwnProfileAsTheAgentOutsideDevelopmentMode(): void
    {
        $factory = $this->factoryWithProfileBuilder(new RecordingProfileBuilder(new PlatformProfile('2026-04-08', [], [], [])));
        $this->runtimeConfiguration = new RuntimeConfiguration('2026-04-08', 'https://merchant.example', SignaturePolicy::Log);

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Platform profile host is not allowed by the current runtime configuration.');

        $factory->create(new HttpRequest('POST', 'https://merchant.example/ucp/v1/checkout-sessions', [
            'UCP-Agent' => 'dev-console; profile="https://merchant.example/.well-known/ucp"',
        ], [], '{}'));
    }

    /**
     * Only the discovery path short-circuits. Any other document on the own host is fetched and
     * checked like a stranger's, so development mode does not turn the host into a wildcard.
     */
    #[Test]
    public function itOnlyShortCircuitsTheOwnDiscoveryPath(): void
    {
        $builder = new RecordingProfileBuilder(new PlatformProfile('2026-04-08', [], [], []));
        $factory = $this->factoryWithProfileBuilder($builder);
        $this->runtimeConfiguration = new RuntimeConfiguration('2026-04-08', 'https://merchant.example', SignaturePolicy::Log, profileFetchingDevelopmentMode: true);

        try {
            $factory->create(new HttpRequest('POST', 'https://merchant.example/ucp/v1/checkout-sessions', [
                'UCP-Agent' => 'dev-console; profile="https://merchant.example/agent-profile.json"',
            ], [], '{}'));
            self::fail('A non-discovery URL on the own host must take the normal path.');
        } catch (SignatureException $exception) {
            self::assertSame('Platform profile host is not allowed by the current runtime configuration.', $exception->getMessage());
        }

        self::assertNull($builder->input, 'The profile builder is not consulted for other paths.');
    }

    private function factoryWithDispatcher(EventDispatcherInterface $dispatcher): DefaultHttpRequestContextFactory
    {
        return $this->factory($dispatcher, null);
    }

    private function factoryWithProfileBuilder(ProfileBuilderInterface $profileBuilder): DefaultHttpRequestContextFactory
    {
        return $this->factory(null, $profileBuilder);
    }

    /**
     * One builder for both: the observation tests need the dispatcher, the development-mode
     * tests need the profile builder, and the factory takes them in that order.
     */
    private function factory(?EventDispatcherInterface $dispatcher, ?ProfileBuilderInterface $profileBuilder): DefaultHttpRequestContextFactory
    {
        $runtimeConfigurationResolver = $this->createMock(RuntimeConfigurationResolverInterface::class);
        $runtimeConfigurationResolver
            ->method('resolve')
            ->willReturnCallback(fn (HttpRequest $request): RuntimeConfiguration => $this->runtimeConfiguration);
        $agentProfileFetcher = $this->createMock(AgentProfileFetcherInterface::class);
        $agentProfileFetcher
            ->method('fetch')
            ->willReturnCallback(function (string $uri): PlatformProfile {
                ++$this->profileFetches;

                return $this->platformProfile;
            });
        $requestSignatureService = $this->createMock(RequestSignatureServiceInterface::class);
        $requestSignatureService
            ->method('verify')
            ->willReturnCallback(fn (): SignatureVerificationResult => $this->signatureVerificationResult);
        $capabilityNegotiator = $this->createMock(CapabilityNegotiatorInterface::class);
        $capabilityNegotiator
            ->method('negotiate')
            ->willReturnCallback(function (?PlatformProfile $platformProfile): NegotiatedCapabilities {
                $this->negotiatedProfile = $platformProfile;

                return $this->negotiatedCapabilities;
            });

        return new DefaultHttpRequestContextFactory(
            $runtimeConfigurationResolver,
            $agentProfileFetcher,
            $requestSignatureService,
            $capabilityNegotiator,
            null,
            null,
            $dispatcher,
            $profileBuilder,
        );
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

    /**
     * An `allowed_agent_domains` entry written as a full origin used to match no host
     * that could exist: this gate compared entries as bare domains, so `https://x` was
     * tested against a host of `x` and never matched. A merchant who wrote origins --
     * which was the only form the embedded transport honoured -- had every platform
     * profile refused.
     */
    #[Test]
    public function itAcceptsAnAgentDomainWrittenAsAFullOrigin(): void
    {
        $this->runtimeConfiguration = new RuntimeConfiguration(
            '2026-04-08',
            'https://merchant.example',
            SignaturePolicy::Log,
            false,
            ['trusted.example'],
            ['https://trusted.example'],
        );

        $context = $this->factory->create(new HttpRequest('GET', 'https://merchant.example/.well-known/ucp', [
            'UCP-Agent' => 'platform; profile="https://trusted.example/.well-known/ucp"',
        ]));

        self::assertSame('https://trusted.example/.well-known/ucp', $context->platformProfileUri);
    }

    /**
     * And a bare domain still covers its subdomains, which is the behaviour this gate
     * always had and the reason the shared list settled on domains rather than origins.
     */
    #[Test]
    public function itAcceptsASubdomainOfAnAllowedAgentDomain(): void
    {
        $this->runtimeConfiguration = new RuntimeConfiguration(
            '2026-04-08',
            'https://merchant.example',
            SignaturePolicy::Log,
            false,
            ['trusted.example'],
            ['trusted.example'],
        );

        $context = $this->factory->create(new HttpRequest('GET', 'https://merchant.example/.well-known/ucp', [
            'UCP-Agent' => 'platform; profile="https://profiles.trusted.example/.well-known/ucp"',
        ]));

        self::assertSame('https://profiles.trusted.example/.well-known/ucp', $context->platformProfileUri);
    }

    #[Test]
    public function itRejectsAProfileHostOutsideTheAllowedAgentDomains(): void
    {
        $this->runtimeConfiguration = new RuntimeConfiguration(
            '2026-04-08',
            'https://merchant.example',
            SignaturePolicy::Log,
            false,
            ['trusted.example'],
            ['https://other-agent.example'],
        );

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Platform agent domain is not allowed for the current runtime configuration.');

        $this->factory->create(new HttpRequest('GET', 'https://merchant.example/.well-known/ucp', [
            'UCP-Agent' => 'platform; profile="https://trusted.example/.well-known/ucp"',
        ]));
    }
}

final class ObservationRecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}

final class RecordingProfileBuilder implements ProfileBuilderInterface
{
    public ?ProfileBuildInput $input = null;

    public function __construct(public readonly PlatformProfile $profile)
    {
    }

    public function build(ProfileBuildInput $input): PlatformProfile
    {
        $this->input = $input;

        return $this->profile;
    }
}
