<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Exception\IdempotencyConflictException;
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
    private HttpKernelInterface $kernel;

    private RequestContext $requestContext;

    private ?IdempotencyRecord $claimRecord = null;

    private ?string $claimedKey = null;

    private ?string $claimedFingerprint = null;

    private int $requestContextCreations = 0;

    private ?IdempotencyRecord $abortedRecord = null;

    private ?IdempotencyRecord $completedRecord = null;

    /** @var array<string, mixed>|null */
    private ?array $completedBody = null;

    private ?int $completedStatusCode = null;

    private bool $completedReplayable = true;

    private UcpSdkConfiguration $configuration;

    private UcpResponseFactory $responseFactory;

    private RequestContextListener $requestContextListener;

    private IdempotencyResponseListener $idempotencyResponseListener;

    protected function setUp(): void
    {
        $this->kernel = $this->createMock(HttpKernelInterface::class);
        $this->configuration = $this->configuration();
        $this->responseFactory = new UcpResponseFactory($this->configuration);
        $this->requestContext = new RequestContext(
            'merchant.example',
            runtimeConfiguration: new RuntimeConfiguration('2026-04-08', 'https://merchant.example'),
        );

        $requestContextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $requestContextFactory
            ->method('create')
            ->willReturnCallback(function (HttpRequest $request): RequestContext {
                ++$this->requestContextCreations;

                return $this->requestContext;
            });
        $idempotencyService = $this->createMock(IdempotencyServiceInterface::class);
        $idempotencyService
            ->method('claim')
            ->willReturnCallback(function (string $key, string $fingerprint): IdempotencyRecord {
                $this->claimedKey = $key;
                $this->claimedFingerprint = $fingerprint;

                return $this->claimRecord ?? new IdempotencyRecord($key, $fingerprint);
            });
        $idempotencyService
            ->method('complete')
            ->willReturnCallback(function (IdempotencyRecord $record, array $responseBody, int $statusCode, bool $replayable = true): void {
                $this->completedRecord = $record;
                $this->completedBody = $responseBody;
                $this->completedStatusCode = $statusCode;
                $this->completedReplayable = $replayable;
            });
        $idempotencyService
            ->method('abort')
            ->willReturnCallback(function (IdempotencyRecord $record): void {
                $this->abortedRecord = $record;
            });

        $this->requestContextListener = new RequestContextListener(
            $requestContextFactory,
            $idempotencyService,
            $this->responseFactory,
            $this->configuration,
        );
        $this->idempotencyResponseListener = new IdempotencyResponseListener(
            $idempotencyService,
            $this->configuration,
        );
    }

    #[Test]
    public function itMapsDomainExceptionsToUcpErrorResponses(): void
    {
        $listener = new ExceptionListener($this->responseFactory);

        $validationEvent = new ExceptionEvent(
            $this->kernel,
            Request::create('/ucp/v1/carts', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            new ValidationException('invalid', ['$.field is required']),
        );
        $listener->onKernelException($validationEvent);
        self::assertSame(422, $validationEvent->getResponse()?->getStatusCode());

        $conflictEvent = new ExceptionEvent(
            $this->kernel,
            Request::create('/ucp/v1/carts', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            new IdempotencyConflictException('conflict'),
        );
        $listener->onKernelException($conflictEvent);
        self::assertSame(409, $conflictEvent->getResponse()?->getStatusCode());

        $signatureEvent = new ExceptionEvent(
            $this->kernel,
            Request::create('/ucp/v1/carts', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            new SignatureException('bad signature'),
        );
        $listener->onKernelException($signatureEvent);
        self::assertSame(401, $signatureEvent->getResponse()?->getStatusCode());

        $unsupportedEvent = new ExceptionEvent(
            $this->kernel,
            Request::create('/ucp/v1/carts', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            new UnsupportedCapabilityException('missing'),
        );
        $listener->onKernelException($unsupportedEvent);
        self::assertSame(501, $unsupportedEvent->getResponse()?->getStatusCode());

        $notFoundEvent = new ExceptionEvent(
            $this->kernel,
            Request::create('/ucp/v1/carts/missing', 'GET'),
            HttpKernelInterface::MAIN_REQUEST,
            new ResourceNotFoundException('missing'),
        );
        $listener->onKernelException($notFoundEvent);
        self::assertSame(404, $notFoundEvent->getResponse()?->getStatusCode());

        $configurationEvent = new ExceptionEvent(
            $this->kernel,
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

        $httpEvent = new ExceptionEvent(
            $this->kernel,
            Request::create('/ucp/v1/carts', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            new BadRequestHttpException('bad request'),
        );
        $listener->onKernelException($httpEvent);
        self::assertSame(400, $httpEvent->getResponse()?->getStatusCode());

        $runtimeEvent = new ExceptionEvent(
            $this->kernel,
            Request::create('/ucp/v1/carts', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('boom'),
        );
        $listener->onKernelException($runtimeEvent);
        self::assertSame(500, $runtimeEvent->getResponse()?->getStatusCode());
    }

    #[Test]
    public function itIgnoresExceptionsOutsideTheUcpSurface(): void
    {
        $listener = new ExceptionListener($this->responseFactory);
        $event = new ExceptionEvent(
            $this->kernel,
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
        $listener = new ExceptionListener($this->responseFactory);
        $event = new ExceptionEvent(
            $this->kernel,
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
        $this->requestContext = new RequestContext(
            'merchant.example',
            idempotencyKey: 'idem-1',
            runtimeConfiguration: new RuntimeConfiguration('2026-04-08', 'https://merchant.example', idempotencyRequired: true),
        );
        $this->claimRecord = new IdempotencyRecord('idem-1', 'fp-1', 'completed', ['ok' => true], 201);

        $request = Request::create('https://merchant.example/ucp/v1/carts?b=2&a=1', 'POST', [], [], [], [], '{"ok":true}');
        $request->headers->set('Idempotency-Key', 'idem-1');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->requestContextListener->onKernelRequest($event);

        self::assertNotNull($request->attributes->get('ucp_request_context'));
        self::assertSame('idem-1', $this->claimedKey);
        self::assertIsString($this->claimedFingerprint);
        self::assertNotNull($event->getResponse());
        self::assertSame('1', $event->getResponse()->headers->get('Idempotency-Replay'));
    }

    #[Test]
    public function itRejectsMutatingRequestsWithoutAnIdempotencyKeyWhenRequired(): void
    {
        $this->requestContext = new RequestContext(
            'merchant.example',
            runtimeConfiguration: new RuntimeConfiguration('2026-04-08', 'https://merchant.example', idempotencyRequired: true),
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Idempotency key is required for mutating UCP requests.');

        $this->requestContextListener->onKernelRequest(new RequestEvent(
            $this->kernel,
            Request::create('https://merchant.example/ucp/v1/carts', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
        ));
    }

    #[Test]
    public function itDoesNotRequireUcpIdempotencyForOAuthTokenRequests(): void
    {
        $this->requestContext = new RequestContext(
            'merchant.example',
            runtimeConfiguration: new RuntimeConfiguration('2026-04-08', 'https://merchant.example', idempotencyRequired: true),
        );

        $request = Request::create('https://merchant.example/ucp/v1/oauth/token', 'POST');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->requestContextListener->onKernelRequest($event);

        self::assertNotNull($request->attributes->get('ucp_request_context'));
        self::assertNull($this->claimedKey);
        self::assertNull($event->getResponse());
    }

    #[Test]
    public function itIgnoresNonUcpRoutes(): void
    {
        $request = Request::create('https://merchant.example/_action/swag-agentic-commerce/test/webhooks', 'POST');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->requestContextListener->onKernelRequest($event);

        self::assertNull($request->attributes->get('ucp_request_context'));
        self::assertSame(0, $this->requestContextCreations);
        self::assertNull($this->claimedKey);
        self::assertNull($event->getResponse());
    }

    #[Test]
    public function itDoesNotBuildRestContextForMcpTransportRequests(): void
    {
        $request = Request::create('https://merchant.example/ucp/mcp', 'POST', [], [], [], [], '{"jsonrpc":"2.0"}');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->requestContextListener->onKernelRequest($event);

        self::assertNull($request->attributes->get('ucp_request_context'));
        self::assertSame(0, $this->requestContextCreations);
        self::assertNull($this->claimedKey);
        self::assertNull($event->getResponse());
    }

    #[Test]
    public function itAbortsOrCompletesPendingIdempotencyRecordsOnResponse(): void
    {
        $abortRequest = Request::create('/ucp/v1/carts', 'POST');
        $abortRequest->attributes->set('ucp_idempotency_record', new IdempotencyRecord('idem-1', 'fp-1'));
        $this->idempotencyResponseListener->onKernelResponse(new ResponseEvent(
            $this->kernel,
            $abortRequest,
            HttpKernelInterface::MAIN_REQUEST,
            new Response('boom', 503),
        ));
        self::assertSame('idem-1', $this->abortedRecord?->key);

        $completeRequest = Request::create('/ucp/v1/carts', 'POST');
        $completeRequest->attributes->set('ucp_idempotency_record', new IdempotencyRecord('idem-2', 'fp-2'));
        $this->idempotencyResponseListener->onKernelResponse(new ResponseEvent(
            $this->kernel,
            $completeRequest,
            HttpKernelInterface::MAIN_REQUEST,
            new Response('not-json', 200),
        ));
        self::assertSame('idem-2', $this->completedRecord?->key);
        self::assertSame([], $this->completedBody);
        self::assertSame(200, $this->completedStatusCode);
        self::assertFalse($this->completedReplayable);
    }

    #[Test]
    public function itReturnsA413EnvelopeWhenTheRequestBodyIsTooLarge(): void
    {
        $requestContextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $requestContextFactory
            ->expects($this->never())
            ->method('create');
        $idempotencyService = $this->createMock(IdempotencyServiceInterface::class);
        $idempotencyService
            ->expects($this->never())
            ->method('claim');
        $configuration = $this->configuration(maxRequestBodyBytes: 4);
        $listener = new RequestContextListener(
            $requestContextFactory,
            $idempotencyService,
            new UcpResponseFactory($configuration),
            $configuration,
        );

        $request = Request::create('https://merchant.example/ucp/v1/carts', 'POST', [], [], [], [], '{"too":"big"}');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

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
