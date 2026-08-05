<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Ucp\Sdk\Exception\AgentProfileException;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Exception\IdempotencyConflictException;
use Ucp\Sdk\Exception\NegotiationException;
use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\IdempotencyRecord;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\HttpRequestContextFactoryInterface;
use Ucp\Sdk\Service\IdempotencyServiceInterface;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;
use Ucp\Sdk\Symfony\EventListener\ExceptionListener;
use Ucp\Sdk\Symfony\EventListener\IdempotencyResponseListener;
use Ucp\Sdk\Symfony\EventListener\RequestContextListener;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

final class EventListenersTest extends TestCase
{
    #[Test]
    public function itMapsDomainExceptionsToUcpErrorResponses(): void
    {
        $listener = new ExceptionListener(new UcpResponseFactory($this->configuration()));
        $kernel = $this->createMock(HttpKernelInterface::class);

        $validationEvent = new ExceptionEvent(
            $kernel,
            Request::create('/ucp/v1/carts', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            new ValidationException('invalid', ['$.field is required']),
        );
        $listener->onKernelException($validationEvent);
        self::assertSame(422, $validationEvent->getResponse()?->getStatusCode());

        $conflictEvent = new ExceptionEvent(
            $kernel,
            Request::create('/ucp/v1/carts', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            new IdempotencyConflictException('conflict'),
        );
        $listener->onKernelException($conflictEvent);
        self::assertSame(409, $conflictEvent->getResponse()?->getStatusCode());

        $signatureEvent = new ExceptionEvent(
            $kernel,
            Request::create('/ucp/v1/carts', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            new SignatureException('bad signature'),
        );
        $listener->onKernelException($signatureEvent);
        self::assertSame(401, $signatureEvent->getResponse()?->getStatusCode());

        $unsupportedEvent = new ExceptionEvent(
            $kernel,
            Request::create('/ucp/v1/carts', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            new UnsupportedCapabilityException('missing'),
        );
        $listener->onKernelException($unsupportedEvent);
        self::assertSame(501, $unsupportedEvent->getResponse()?->getStatusCode());

        $negotiationEvent = new ExceptionEvent(
            $kernel,
            Request::create('/ucp/v1/carts', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            NegotiationException::capabilitiesIncompatible('capability mismatch'),
        );
        $listener->onKernelException($negotiationEvent);
        $negotiationResponse = $negotiationEvent->getResponse();
        self::assertNotNull($negotiationResponse);
        self::assertSame(400, $negotiationResponse->getStatusCode());
        self::assertSame(
            'capabilities_incompatible',
            json_decode((string) $negotiationResponse->getContent(), true, 512, \JSON_THROW_ON_ERROR)['messages'][0]['code'],
        );

        $notFoundEvent = new ExceptionEvent(
            $kernel,
            Request::create('/ucp/v1/carts/missing', 'GET'),
            HttpKernelInterface::MAIN_REQUEST,
            new ResourceNotFoundException('missing'),
        );
        $listener->onKernelException($notFoundEvent);
        self::assertSame(404, $notFoundEvent->getResponse()?->getStatusCode());

        $configurationEvent = new ExceptionEvent(
            $kernel,
            Request::create('/.well-known/ucp', 'GET'),
            HttpKernelInterface::MAIN_REQUEST,
            new ConfigurationException('misconfigured'),
        );
        $listener->onKernelException($configurationEvent);
        $configurationResponse = $configurationEvent->getResponse();
        self::assertNotNull($configurationResponse);
        self::assertSame(500, $configurationResponse->getStatusCode());
        self::assertSame(
            'misconfigured',
            json_decode((string) $configurationResponse->getContent(), true, 512, \JSON_THROW_ON_ERROR)['messages'][0]['content'],
        );

        $agentProfileEvent = new ExceptionEvent(
            $kernel,
            Request::create('/ucp/v1/carts', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            AgentProfileException::unreachable('https://agent.example/.well-known/ucp', new \RuntimeException('Connection refused.')),
        );
        $listener->onKernelException($agentProfileEvent);
        $agentProfileResponse = $agentProfileEvent->getResponse();
        self::assertNotNull($agentProfileResponse);
        self::assertSame(424, $agentProfileResponse->getStatusCode());
        self::assertSame(
            [[
                'type' => 'error',
                'content' => 'Platform profile at "https://agent.example/.well-known/ucp" could not be fetched: Connection refused.',
                'severity' => 'recoverable',
                'code' => 'agent_profile_unreachable',
            ]],
            json_decode((string) $agentProfileResponse->getContent(), true, 512, \JSON_THROW_ON_ERROR)['messages'],
        );

        $httpEvent = new ExceptionEvent(
            $kernel,
            Request::create('/ucp/v1/carts', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            new BadRequestHttpException('bad request'),
        );
        $listener->onKernelException($httpEvent);
        self::assertSame(400, $httpEvent->getResponse()?->getStatusCode());

        $runtimeEvent = new ExceptionEvent(
            $kernel,
            Request::create('/ucp/v1/carts', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('boom'),
        );
        $listener->onKernelException($runtimeEvent);
        self::assertSame(500, $runtimeEvent->getResponse()?->getStatusCode());
    }

    #[Test]
    public function itLogsUnhandledServerExceptionsWithTheThrowable(): void
    {
        $throwable = new \RuntimeException('boom');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Unhandled exception while processing a UCP request.', ['exception' => $throwable]);

        $listener = new ExceptionListener(new UcpResponseFactory($this->configuration()), $logger);
        $event = new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/.well-known/ucp', 'GET'),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );

        $listener->onKernelException($event);

        self::assertSame(500, $event->getResponse()?->getStatusCode());
    }

    #[Test]
    public function itLogsConfigurationErrorsWithTheThrowable(): void
    {
        $throwable = new ConfigurationException('misconfigured');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('UCP request failed because of a server configuration error.', ['exception' => $throwable]);

        $listener = new ExceptionListener(new UcpResponseFactory($this->configuration()), $logger);
        $event = new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/.well-known/ucp', 'GET'),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );

        $listener->onKernelException($event);

        self::assertSame(500, $event->getResponse()?->getStatusCode());
    }

    #[Test]
    public function itLogsAgentProfileFetchFailuresWithTheThrowable(): void
    {
        $throwable = AgentProfileException::unavailable('https://agent.example/.well-known/ucp', 503);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('UCP request failed because the agent profile could not be fetched.', ['exception' => $throwable]);

        $listener = new ExceptionListener(new UcpResponseFactory($this->configuration()), $logger);
        $event = new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/.well-known/ucp', 'GET'),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );

        $listener->onKernelException($event);

        self::assertSame(424, $event->getResponse()?->getStatusCode());
    }

    #[Test]
    public function itDoesNotLogExpectedClientErrors(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $listener = new ExceptionListener(new UcpResponseFactory($this->configuration()), $logger);
        $event = new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/ucp/v1/carts', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            new ValidationException('invalid', ['$.field is required']),
        );

        $listener->onKernelException($event);

        self::assertSame(422, $event->getResponse()?->getStatusCode());
    }

    #[Test]
    public function itIgnoresExceptionsOutsideTheUcpSurface(): void
    {
        $listener = new ExceptionListener(new UcpResponseFactory($this->configuration()));
        $kernel = $this->createMock(HttpKernelInterface::class);
        $event = new ExceptionEvent(
            $kernel,
            Request::create('/admin', 'GET'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('boom'),
        );

        $listener->onKernelException($event);

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function itIgnoresMcpTransportExceptions(): void
    {
        $listener = new ExceptionListener(new UcpResponseFactory($this->configuration()));
        $kernel = $this->createMock(HttpKernelInterface::class);
        $event = new ExceptionEvent(
            $kernel,
            Request::create('/ucp/mcp', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('boom'),
        );

        $listener->onKernelException($event);

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function itBuildsRequestContextAndReplaysCompletedIdempotentRequests(): void
    {
        $state = new EventListenerState();
        $contextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $contextFactory->expects(self::once())
            ->method('create')
            ->willReturnCallback(static fn (HttpRequest $request): RequestContext => new RequestContext(
                'merchant.example',
                $request->headers,
                idempotencyKey: 'idem-1',
                runtimeConfiguration: new RuntimeConfiguration('2026-04-08', 'https://merchant.example', idempotencyRequired: true),
            ));
        $idempotencyService = $this->createMock(IdempotencyServiceInterface::class);
        $idempotencyService->expects(self::once())
            ->method('claim')
            ->with('idem-1', self::isType('string'))
            ->willReturnCallback(static function (string $key, string $fingerprint) use ($state): IdempotencyRecord {
                $state->claimedKey = $key;
                $state->claimedFingerprint = $fingerprint;

                return new IdempotencyRecord($key, $fingerprint, 'completed', ['ok' => true], 201);
            });
        $idempotencyService->expects(self::never())->method('complete');
        $idempotencyService->expects(self::never())->method('abort');

        $listener = new RequestContextListener(
            $contextFactory,
            $idempotencyService,
            new UcpResponseFactory($this->configuration()),
            $this->configuration(),
        );

        $request = Request::create('https://merchant.example/ucp/v1/carts?b=2&a=1', 'POST', [], [], [], [], '{"ok":true}');
        $request->headers->set('Idempotency-Key', 'idem-1');
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        $listener->onKernelRequest($event);

        self::assertNotNull($request->attributes->get('ucp_request_context'));
        self::assertSame('idem-1', $state->claimedKey);
        self::assertIsString($state->claimedFingerprint);
        self::assertNotNull($event->getResponse());
        self::assertSame('1', $event->getResponse()->headers->get('Idempotency-Replay'));
    }

    #[Test]
    public function itRejectsMutatingRequestsWithoutAnIdempotencyKeyWhenRequired(): void
    {
        $contextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $contextFactory->expects(self::once())
            ->method('create')
            ->willReturnCallback(static fn (HttpRequest $request): RequestContext => new RequestContext(
                'merchant.example',
                $request->headers,
                runtimeConfiguration: new RuntimeConfiguration('2026-04-08', 'https://merchant.example', idempotencyRequired: true),
            ));
        $idempotencyService = $this->createMock(IdempotencyServiceInterface::class);
        $idempotencyService->expects(self::never())->method('claim');
        $idempotencyService->expects(self::never())->method('complete');
        $idempotencyService->expects(self::never())->method('abort');

        $listener = new RequestContextListener(
            $contextFactory,
            $idempotencyService,
            new UcpResponseFactory($this->configuration()),
            $this->configuration(),
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Idempotency key is required for mutating UCP requests.');

        $listener->onKernelRequest(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('https://merchant.example/ucp/v1/carts', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
        ));
    }

    #[Test]
    public function itDoesNotRequireUcpIdempotencyForOAuthTokenRequests(): void
    {
        $contextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $contextFactory->expects(self::once())
            ->method('create')
            ->willReturnCallback(static fn (HttpRequest $request): RequestContext => new RequestContext(
                'merchant.example',
                $request->headers,
                runtimeConfiguration: new RuntimeConfiguration('2026-04-08', 'https://merchant.example', idempotencyRequired: true),
            ));
        $idempotencyService = $this->createMock(IdempotencyServiceInterface::class);
        $idempotencyService->expects(self::never())->method('claim');
        $idempotencyService->expects(self::never())->method('complete');
        $idempotencyService->expects(self::never())->method('abort');

        $listener = new RequestContextListener(
            $contextFactory,
            $idempotencyService,
            new UcpResponseFactory($this->configuration()),
            $this->configuration(),
        );

        $request = Request::create('https://merchant.example/ucp/v1/oauth/token', 'POST');
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        $listener->onKernelRequest($event);

        self::assertNotNull($request->attributes->get('ucp_request_context'));
        self::assertNull($event->getResponse());
    }

    #[Test]
    public function itIgnoresNonUcpRoutes(): void
    {
        $contextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $contextFactory->expects(self::never())->method('create');
        $idempotencyService = $this->createMock(IdempotencyServiceInterface::class);
        $idempotencyService->expects(self::never())->method('claim');
        $idempotencyService->expects(self::never())->method('complete');
        $idempotencyService->expects(self::never())->method('abort');

        $listener = new RequestContextListener(
            $contextFactory,
            $idempotencyService,
            new UcpResponseFactory($this->configuration()),
            $this->configuration(),
        );

        $request = Request::create('https://merchant.example/_action/swag-agentic-commerce/test/webhooks', 'POST');
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        $listener->onKernelRequest($event);

        self::assertNull($request->attributes->get('ucp_request_context'));
        self::assertNull($event->getResponse());
    }

    #[Test]
    public function itDoesNotBuildRestContextForPublicDiscoveryRoutes(): void
    {
        $contextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $contextFactory->expects(self::never())->method('create');
        $idempotencyService = $this->createMock(IdempotencyServiceInterface::class);
        $idempotencyService->expects(self::never())->method('claim');
        $idempotencyService->expects(self::never())->method('complete');
        $idempotencyService->expects(self::never())->method('abort');

        $listener = new RequestContextListener(
            $contextFactory,
            $idempotencyService,
            new UcpResponseFactory($this->configuration()),
            $this->configuration(),
        );

        foreach ([
            '/.well-known/ucp',
            '/.well-known/oauth-authorization-server',
            '/.well-known/openid-configuration',
            '/.well-known/agent-card.json',
        ] as $path) {
            $request = Request::create('https://merchant.example' . $path, 'GET');
            $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

            $listener->onKernelRequest($event);

            self::assertNull($request->attributes->get('ucp_request_context'), $path);
            self::assertNull($event->getResponse(), $path);
        }
    }

    #[Test]
    public function itDoesNotBuildRestContextForNonRestTransportRequests(): void
    {
        $contextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $contextFactory->expects(self::never())->method('create');
        $idempotencyService = $this->createMock(IdempotencyServiceInterface::class);
        $idempotencyService->expects(self::never())->method('claim');
        $idempotencyService->expects(self::never())->method('complete');
        $idempotencyService->expects(self::never())->method('abort');

        $listener = new RequestContextListener(
            $contextFactory,
            $idempotencyService,
            new UcpResponseFactory($this->configuration()),
            $this->configuration(),
        );

        foreach ([
            '/ucp/mcp',
            '/ucp/a2a',
            '/ucp/embedded/cart/cart-demo',
        ] as $path) {
            $request = Request::create('https://merchant.example' . $path, 'POST', [], [], [], [], '{"jsonrpc":"2.0"}');
            $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

            $listener->onKernelRequest($event);

            self::assertNull($request->attributes->get('ucp_request_context'), $path);
            self::assertNull($event->getResponse(), $path);
        }
    }

    #[Test]
    public function itAbortsOrCompletesPendingIdempotencyRecordsOnResponse(): void
    {
        $state = new EventListenerState();
        $idempotencyService = $this->createMock(IdempotencyServiceInterface::class);
        $idempotencyService->expects(self::never())->method('claim');
        $idempotencyService->expects(self::once())
            ->method('complete')
            ->willReturnCallback(static function (IdempotencyRecord $record, array $responseBody, int $statusCode, bool $replayable = true) use ($state): void {
                $state->completedRecord = $record;
                $state->completedBody = $responseBody;
                $state->completedStatusCode = $statusCode;
                $state->completedReplayable = $replayable;
            });
        $idempotencyService->expects(self::once())
            ->method('abort')
            ->willReturnCallback(static function (IdempotencyRecord $record) use ($state): void {
                $state->abortedRecord = $record;
            });

        $listener = new IdempotencyResponseListener(
            $idempotencyService,
            $this->configuration(),
        );

        $abortRequest = Request::create('/ucp/v1/carts', 'POST');
        $abortRequest->attributes->set('ucp_idempotency_record', new IdempotencyRecord('idem-1', 'fp-1'));
        $listener->onKernelResponse(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $abortRequest,
            HttpKernelInterface::MAIN_REQUEST,
            new Response('boom', 503),
        ));
        self::assertSame('idem-1', $state->abortedRecord?->key);

        $completeRequest = Request::create('/ucp/v1/carts', 'POST');
        $completeRequest->attributes->set('ucp_idempotency_record', new IdempotencyRecord('idem-2', 'fp-2'));
        $listener->onKernelResponse(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $completeRequest,
            HttpKernelInterface::MAIN_REQUEST,
            new Response('not-json', 200),
        ));
        self::assertSame('idem-2', $state->completedRecord?->key);
        self::assertSame([], $state->completedBody);
        self::assertSame(200, $state->completedStatusCode);
        self::assertFalse($state->completedReplayable);
    }

    #[Test]
    public function itReturnsA413EnvelopeWhenTheRequestBodyIsTooLarge(): void
    {
        $contextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $contextFactory->expects(self::never())->method('create');
        $idempotencyService = $this->createMock(IdempotencyServiceInterface::class);
        $idempotencyService->expects(self::never())->method('claim');
        $idempotencyService->expects(self::never())->method('complete');
        $idempotencyService->expects(self::never())->method('abort');

        $listener = new RequestContextListener(
            $contextFactory,
            $idempotencyService,
            new UcpResponseFactory($this->configuration(maxRequestBodyBytes: 4)),
            $this->configuration(maxRequestBodyBytes: 4),
        );

        $request = Request::create('https://merchant.example/ucp/v1/carts', 'POST', [], [], [], [], '{"too":"big"}');
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        $listener->onKernelRequest($event);

        self::assertSame(413, $event->getResponse()?->getStatusCode());
    }

    private function configuration(int $maxRequestBodyBytes = 262144): UcpSdkConfiguration
    {
        return new UcpSdkConfiguration(
            '2026-04-08',
            'https://merchant.example',
            [],
            'log',
            [],
            false,
            86400,
            $maxRequestBodyBytes,
            600,
            604800,
            300,
            600,
            [],
            false,
            'default',
            'ES256',
            'P30D',
            'P30D',
            262144,
            10,
            false,
            'sqlite:///%kernel.project_dir%/var/ucp_sdk.sqlite',
        );
    }
}

final class EventListenerState
{
    public ?string $claimedKey = null;

    public ?string $claimedFingerprint = null;

    public ?IdempotencyRecord $abortedRecord = null;

    public ?IdempotencyRecord $completedRecord = null;

    /** @var array<string, mixed>|null */
    public ?array $completedBody = null;

    public ?int $completedStatusCode = null;

    public bool $completedReplayable = true;
}
