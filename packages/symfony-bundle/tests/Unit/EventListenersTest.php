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
use Ucp\Sdk\Exception\IdempotencyConflictException;
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
    public function itBuildsRequestContextAndReplaysCompletedIdempotentRequests(): void
    {
        $state = new EventListenerState();
        $listener = new RequestContextListener(
            new class () implements HttpRequestContextFactoryInterface {
                public function create(HttpRequest $request): RequestContext
                {
                    return new RequestContext(
                        'merchant.example',
                        $request->headers,
                        idempotencyKey: 'idem-1',
                        runtimeConfiguration: new RuntimeConfiguration('2026-04-08', 'https://merchant.example', idempotencyRequired: true),
                    );
                }
            },
            new class ($state) implements IdempotencyServiceInterface {
                public function __construct(private readonly EventListenerState $state)
                {
                }

                public function claim(string $key, string $fingerprint): IdempotencyRecord
                {
                    $this->state->claimedKey = $key;
                    $this->state->claimedFingerprint = $fingerprint;

                    return new IdempotencyRecord($key, $fingerprint, 'completed', ['ok' => true], 201);
                }

                public function complete(IdempotencyRecord $record, array $responseBody, int $statusCode, bool $replayable = true): void
                {
                }

                public function abort(IdempotencyRecord $record): void
                {
                }
            },
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
        $listener = new RequestContextListener(
            new class () implements HttpRequestContextFactoryInterface {
                public function create(HttpRequest $request): RequestContext
                {
                    return new RequestContext(
                        'merchant.example',
                        $request->headers,
                        runtimeConfiguration: new RuntimeConfiguration('2026-04-08', 'https://merchant.example', idempotencyRequired: true),
                    );
                }
            },
            new class () implements IdempotencyServiceInterface {
                public function claim(string $key, string $fingerprint): IdempotencyRecord
                {
                    throw new \RuntimeException('Not used in this test.');
                }

                public function complete(IdempotencyRecord $record, array $responseBody, int $statusCode, bool $replayable = true): void
                {
                }

                public function abort(IdempotencyRecord $record): void
                {
                }
            },
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
        $listener = new RequestContextListener(
            new class () implements HttpRequestContextFactoryInterface {
                public function create(HttpRequest $request): RequestContext
                {
                    return new RequestContext(
                        'merchant.example',
                        $request->headers,
                        runtimeConfiguration: new RuntimeConfiguration('2026-04-08', 'https://merchant.example', idempotencyRequired: true),
                    );
                }
            },
            new class () implements IdempotencyServiceInterface {
                public function claim(string $key, string $fingerprint): IdempotencyRecord
                {
                    throw new \RuntimeException('OAuth token requests must not claim UCP idempotency records.');
                }

                public function complete(IdempotencyRecord $record, array $responseBody, int $statusCode, bool $replayable = true): void
                {
                }

                public function abort(IdempotencyRecord $record): void
                {
                }
            },
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
        $state = new EventListenerState();
        $listener = new RequestContextListener(
            new class () implements HttpRequestContextFactoryInterface {
                public function create(HttpRequest $request): RequestContext
                {
                    throw new \RuntimeException('Should not be called for non-UCP routes.');
                }
            },
            new class ($state) implements IdempotencyServiceInterface {
                public function __construct(private readonly EventListenerState $state)
                {
                }

                public function claim(string $key, string $fingerprint): IdempotencyRecord
                {
                    $this->state->claimedKey = $key;

                    throw new \RuntimeException('Should not be called for non-UCP routes.');
                }

                public function complete(IdempotencyRecord $record, array $responseBody, int $statusCode, bool $replayable = true): void
                {
                }

                public function abort(IdempotencyRecord $record): void
                {
                }
            },
            new UcpResponseFactory($this->configuration()),
            $this->configuration(),
        );

        $request = Request::create('https://merchant.example/_action/swag-agentic-commerce/test/webhooks', 'POST');
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        $listener->onKernelRequest($event);

        self::assertNull($request->attributes->get('ucp_request_context'));
        self::assertNull($state->claimedKey);
        self::assertNull($event->getResponse());
    }

    #[Test]
    public function itAbortsOrCompletesPendingIdempotencyRecordsOnResponse(): void
    {
        $state = new EventListenerState();
        $listener = new IdempotencyResponseListener(
            new class ($state) implements IdempotencyServiceInterface {
                public function __construct(private readonly EventListenerState $state)
                {
                }

                public function claim(string $key, string $fingerprint): IdempotencyRecord
                {
                    throw new \RuntimeException('Not used in this test.');
                }

                public function complete(IdempotencyRecord $record, array $responseBody, int $statusCode, bool $replayable = true): void
                {
                    $this->state->completedRecord = $record;
                    $this->state->completedBody = $responseBody;
                    $this->state->completedStatusCode = $statusCode;
                    $this->state->completedReplayable = $replayable;
                }

                public function abort(IdempotencyRecord $record): void
                {
                    $this->state->abortedRecord = $record;
                }
            },
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
        $listener = new RequestContextListener(
            new class () implements HttpRequestContextFactoryInterface {
                public function create(HttpRequest $request): RequestContext
                {
                    throw new \RuntimeException('Should not be called.');
                }
            },
            new class () implements IdempotencyServiceInterface {
                public function claim(string $key, string $fingerprint): IdempotencyRecord
                {
                    throw new \RuntimeException('Not used in this test.');
                }

                public function complete(IdempotencyRecord $record, array $responseBody, int $statusCode, bool $replayable = true): void
                {
                }

                public function abort(IdempotencyRecord $record): void
                {
                }
            },
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
