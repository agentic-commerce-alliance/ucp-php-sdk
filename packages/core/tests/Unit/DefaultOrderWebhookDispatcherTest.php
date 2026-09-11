<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Contract\OrderWebhookEnricherInterface;
use Ucp\Sdk\Event\OrderWebhookDispatchEvent;
use Ucp\Sdk\Exception\UcpException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Internal\Service\DefaultOrderWebhookDispatcher;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Http\HttpResponseChunkInterface;
use Ucp\Sdk\Model\Http\HttpResponseInterface;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Repository\TenantAwareManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\EventDispatcherInterface;
use Ucp\Sdk\Service\HttpClientInterface;
use Ucp\Sdk\Service\RequestSignatureServiceInterface;

final class DefaultOrderWebhookDispatcherTest extends TestCase
{
    #[Test]
    public function itPublishesSignedWebhooksAndMapsTheResponse(): void
    {
        $state = new WebhookDispatcherState();
        $activeKey = new ManagedSigningKey('active', 'public', 'private');
        $signingKeys = $this->createMock(ManagedSigningKeyRepositoryInterface::class);
        $signingKeys
            ->method('active')
            ->willReturn([$activeKey]);
        $signatures = $this->createMock(RequestSignatureServiceInterface::class);
        $signatures
            ->method('sign')
            ->willReturnCallback(static function (HttpRequest $request, ManagedSigningKey $key) use ($state): array {
                $state->capturedRequest = $request;
                $state->capturedKey = $key;

                return ['Signature' => 'signed'];
            });
        $enricher = $this->createMock(OrderWebhookEnricherInterface::class);
        $enricher
            ->method('enrich')
            ->willReturnCallback(static fn (OrderWebhookPayload $payload, RequestContext $context): OrderWebhookPayload => new OrderWebhookPayload($payload->event, $payload->orderId, $payload->payload + ['enriched' => true]));
        $dispatcher = new DefaultOrderWebhookDispatcher(
            $signingKeys,
            $signatures,
            WebhookRecordingHttpClient::responding(new WebhookRecordingResponse(202, ['x-webhook-id' => ['demo-1']], 'accepted')),
            [$enricher],
            new RecordingEventDispatcher(),
            10,
            self::safeWebhookUrlValidator(),
        );

        $result = $dispatcher->publish(
            'https://platform.example/webhooks/orders',
            new OrderWebhookPayload('order.created', 'order-1', ['source' => 'sdk']),
            new RequestContext('merchant.example'),
        );

        self::assertFalse($result->retryable);
        self::assertTrue($result->successful);
        self::assertSame(202, $result->statusCode);
        self::assertSame('accepted', $result->responseBody);
        self::assertSame('demo-1', $result->responseHeaders['x-webhook-id']);
        self::assertInstanceOf(HttpRequest::class, $state->capturedRequest);
        self::assertNotNull($state->capturedKey);
        self::assertSame('active', $state->capturedKey->kid);
        self::assertStringContainsString('"enriched":true', $state->capturedRequest->body);
    }

    #[Test]
    public function itReturnsARetryableResultOnTransportFailure(): void
    {
        $signingKeys = $this->createMock(ManagedSigningKeyRepositoryInterface::class);
        $signingKeys
            ->method('active')
            ->willReturn([new ManagedSigningKey('fallback', 'public', 'private')]);
        $signatures = $this->createMock(RequestSignatureServiceInterface::class);
        $signatures
            ->method('sign')
            ->willReturn(['Signature' => 'signed']);
        $dispatcher = new DefaultOrderWebhookDispatcher(
            $signingKeys,
            $signatures,
            WebhookRecordingHttpClient::failing(new \RuntimeException('network down')),
            [],
            new RecordingEventDispatcher(),
            10,
            self::safeWebhookUrlValidator(),
        );

        $result = $dispatcher->publish(
            'https://platform.example/webhooks/orders',
            new OrderWebhookPayload('order.created', 'order-3'),
            new RequestContext('merchant.example'),
        );

        self::assertSame(0, $result->statusCode);
        self::assertFalse($result->successful);
        self::assertTrue($result->retryable);
    }

    #[Test]
    public function itThrowsWhenNoSigningKeyIsAvailable(): void
    {
        $signingKeys = $this->createMock(ManagedSigningKeyRepositoryInterface::class);
        $signingKeys
            ->method('active')
            ->willReturn([]);
        $dispatcher = new DefaultOrderWebhookDispatcher(
            $signingKeys,
            $this->createMock(RequestSignatureServiceInterface::class),
            WebhookRecordingHttpClient::responding(new WebhookRecordingResponse(204)),
            [],
            new RecordingEventDispatcher(),
            10,
            self::safeWebhookUrlValidator(),
        );

        $this->expectException(UcpException::class);
        $this->expectExceptionMessage('No signing key available for webhook dispatch.');

        $dispatcher->publish(
            'https://platform.example/webhooks/orders',
            new OrderWebhookPayload('order.created', 'order-4'),
            new RequestContext('merchant.example'),
        );
    }

    #[Test]
    public function itRejectsUnsafeWebhookTargetUrlsBeforeDispatching(): void
    {
        $requestAttempted = false;
        $signingKeyRepository = $this->createMock(ManagedSigningKeyRepositoryInterface::class);
        $signingKeyRepository
            ->expects(self::never())
            ->method('active');
        $signatureService = $this->createMock(RequestSignatureServiceInterface::class);
        $signatureService
            ->expects(self::never())
            ->method('sign');

        $dispatcher = new DefaultOrderWebhookDispatcher(
            $signingKeyRepository,
            $signatureService,
            WebhookRecordingHttpClient::callback(static function () use (&$requestAttempted): WebhookRecordingResponse {
                $requestAttempted = true;

                return new WebhookRecordingResponse(204);
            }),
            [],
            new RecordingEventDispatcher(),
            10,
            self::safeWebhookUrlValidator(),
        );

        $this->expectException(ValidationException::class);

        try {
            $dispatcher->publish(
                'http://169.254.169.254/latest/meta-data',
                new OrderWebhookPayload('order.created', 'order-5'),
                new RequestContext('merchant.example'),
            );
        } finally {
            self::assertFalse($requestAttempted);
        }
    }

    #[Test]
    public function itDoesNotStoreOversizedWebhookResponseBodies(): void
    {
        $activeKey = new ManagedSigningKey('active', 'public', 'private');
        $signingKeys = $this->createMock(ManagedSigningKeyRepositoryInterface::class);
        $signingKeys
            ->method('active')
            ->willReturn([$activeKey]);
        $signatureService = $this->createMock(RequestSignatureServiceInterface::class);
        $signatureService
            ->method('sign')
            ->willReturn(['Signature' => 'signed']);

        $dispatcher = new DefaultOrderWebhookDispatcher(
            $signingKeys,
            $signatureService,
            WebhookRecordingHttpClient::responding(new WebhookRecordingResponse(202, ['content-length' => ['262145']], str_repeat('x', 262145))),
            [],
            new RecordingEventDispatcher(),
            10,
            self::safeWebhookUrlValidator(),
        );

        $result = $dispatcher->publish(
            'https://platform.example/webhooks/orders',
            new OrderWebhookPayload('order.created', 'order-6'),
            new RequestContext('merchant.example'),
        );

        self::assertSame(202, $result->statusCode);
        self::assertTrue($result->successful);
        self::assertNull($result->responseBody);
    }

    #[Test]
    public function itUsesTenantAwareSigningKeysWhenAvailable(): void
    {
        $state = new WebhookDispatcherState();
        $tenantKey = new ManagedSigningKey('tenant-key', 'public', 'private');
        $signingKeyRepository = $this->createMock(TenantAwareSigningKeyRepositoryMock::class);
        $signingKeyRepository
            ->expects(self::once())
            ->method('activeForTenant')
            ->with('tenant-a')
            ->willReturn([$tenantKey]);

        $signatureService = $this->createMock(RequestSignatureServiceInterface::class);
        $signatureService
            ->expects(self::once())
            ->method('sign')
            ->with(self::isInstanceOf(HttpRequest::class), self::identicalTo($tenantKey))
            ->willReturnCallback(static function (HttpRequest $request, ManagedSigningKey $key) use ($state): array {
                $state->capturedRequest = $request;
                $state->capturedKey = $key;

                return ['Signature' => 'signed'];
            });

        $dispatcher = new DefaultOrderWebhookDispatcher(
            $signingKeyRepository,
            $signatureService,
            WebhookRecordingHttpClient::responding(new WebhookRecordingResponse(204)),
            [],
            new RecordingEventDispatcher(),
            10,
            self::safeWebhookUrlValidator(),
        );

        $dispatcher->publish(
            'https://platform.example/webhooks/orders',
            new OrderWebhookPayload('order.created', 'order-7'),
            new RequestContext(
                'merchant.example',
                runtimeConfiguration: new RuntimeConfiguration(
                    '2026-04-08',
                    'https://merchant.example',
                    tenantIdentifier: 'tenant-a',
                ),
            ),
        );

        self::assertNotNull($state->capturedKey);
        self::assertSame('tenant-key', $state->capturedKey->kid);
    }

    /**
     * The enricher chain is already covered; this is the other extension point, and the
     * two are not interchangeable. An enricher returns a payload and the dispatcher uses
     * the return value; a listener is handed a mutable event and has to call
     * replacePayload() for its work to survive. Nothing tested that it does -- the
     * dispatcher could have ignored the event entirely and every existing assertion
     * would still pass, because the shared RecordingEventDispatcher is a no-op.
     *
     * The assertion is on the signed body rather than on the event object, because
     * replacing a payload that then does not reach the wire is the failure worth
     * catching.
     */
    #[Test]
    public function aListenerReplacingThePayloadChangesWhatIsSigned(): void
    {
        $state = new WebhookDispatcherState();
        $signingKeys = $this->createMock(ManagedSigningKeyRepositoryInterface::class);
        $signingKeys
            ->method('active')
            ->willReturn([new ManagedSigningKey('active', 'public', 'private')]);
        $signatures = $this->createMock(RequestSignatureServiceInterface::class);
        $signatures
            ->method('sign')
            ->willReturnCallback(static function (HttpRequest $request, ManagedSigningKey $key) use ($state): array {
                $state->capturedRequest = $request;

                return ['Signature' => 'signed'];
            });
        $listener = new PayloadReplacingWebhookListener();
        $dispatcher = new DefaultOrderWebhookDispatcher(
            $signingKeys,
            $signatures,
            WebhookRecordingHttpClient::responding(new WebhookRecordingResponse(202, [], 'accepted')),
            [],
            $listener,
            10,
            self::safeWebhookUrlValidator(),
        );

        $dispatcher->publish(
            'https://platform.example/webhooks/orders',
            new OrderWebhookPayload('order.created', 'order-1', ['source' => 'sdk']),
            new RequestContext('merchant.example'),
        );

        self::assertInstanceOf(HttpRequest::class, $state->capturedRequest);
        self::assertStringContainsString('"replaced":true', $state->capturedRequest->body);
        self::assertStringNotContainsString('"source":"sdk"', $state->capturedRequest->body);
    }

    /**
     * A listener deciding whether to rewrite a webhook needs to know where it is going
     * and for whom -- a tenant-specific redaction rule is the obvious case. Both are
     * carried on the event and neither was ever read, so this pins that they arrive
     * rather than that the accessors exist.
     */
    #[Test]
    public function theDispatchEventCarriesTheTargetUrlAndTheRequestContext(): void
    {
        $signingKeys = $this->createMock(ManagedSigningKeyRepositoryInterface::class);
        $signingKeys
            ->method('active')
            ->willReturn([new ManagedSigningKey('active', 'public', 'private')]);
        $signatures = $this->createMock(RequestSignatureServiceInterface::class);
        $signatures->method('sign')->willReturn(['Signature' => 'signed']);
        $listener = new PayloadReplacingWebhookListener();
        $dispatcher = new DefaultOrderWebhookDispatcher(
            $signingKeys,
            $signatures,
            WebhookRecordingHttpClient::responding(new WebhookRecordingResponse(202, [], 'accepted')),
            [],
            $listener,
            10,
            self::safeWebhookUrlValidator(),
        );
        $context = new RequestContext('merchant.example');

        $dispatcher->publish(
            'https://platform.example/webhooks/orders',
            new OrderWebhookPayload('order.created', 'order-1', []),
            $context,
        );

        self::assertSame('https://platform.example/webhooks/orders', $listener->targetUrl);
        self::assertSame($context, $listener->context);
        self::assertSame('order-1', $listener->seenPayload?->orderId);
    }

    private static function safeWebhookUrlValidator(): UrlSafetyValidator
    {
        return new UrlSafetyValidator(
            ['platform.example'],
            static fn (string $host): array => $host === 'platform.example' ? ['203.0.113.10'] : [],
        );
    }
}

final class WebhookDispatcherState
{
    public ?HttpRequest $capturedRequest = null;

    public ?ManagedSigningKey $capturedKey = null;
}

interface TenantAwareSigningKeyRepositoryMock extends ManagedSigningKeyRepositoryInterface, TenantAwareManagedSigningKeyRepositoryInterface
{
}

final class WebhookRecordingHttpClient implements HttpClientInterface
{
    /**
     * @param \Closure(string, string, array<string, mixed>): HttpResponseInterface $callback
     */
    private function __construct(
        private readonly \Closure $callback,
    ) {
    }

    public static function responding(HttpResponseInterface $response): self
    {
        return new self(static fn (): HttpResponseInterface => $response);
    }

    public static function failing(\Throwable $throwable): self
    {
        return new self(static fn () => throw $throwable);
    }

    /**
     * @param \Closure(string, string, array<string, mixed>): HttpResponseInterface $callback
     */
    public static function callback(\Closure $callback): self
    {
        return new self($callback);
    }

    public function request(string $method, string $url, array $options = []): HttpResponseInterface
    {
        return ($this->callback)($method, $url, $options);
    }

    public function stream(HttpResponseInterface $response, ?float $timeout = null): iterable
    {
        if (! $response instanceof WebhookRecordingResponse) {
            return [];
        }

        return [new WebhookRecordingChunk($response->body)];
    }
}

final class WebhookRecordingResponse implements HttpResponseInterface
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->headers;
    }

    public function cancel(): void
    {
    }
}

final class WebhookRecordingChunk implements HttpResponseChunkInterface
{
    public function __construct(
        private readonly string $content,
    ) {
    }

    public function isTimeout(): bool
    {
        return false;
    }

    public function isFirst(): bool
    {
        return false;
    }

    public function getContent(): string
    {
        return $this->content;
    }
}

final class RecordingEventDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        return $event;
    }
}

final class PayloadReplacingWebhookListener implements EventDispatcherInterface
{
    public ?string $targetUrl = null;

    public ?RequestContext $context = null;

    public ?OrderWebhookPayload $seenPayload = null;

    public function dispatch(object $event): object
    {
        if (! $event instanceof OrderWebhookDispatchEvent) {
            return $event;
        }

        $this->targetUrl = $event->getTargetUrl();
        $this->context = $event->getContext();
        $this->seenPayload = $event->getPayload();

        $event->replacePayload(new OrderWebhookPayload(
            $this->seenPayload->event,
            $this->seenPayload->orderId,
            ['replaced' => true],
        ));

        return $event;
    }
}
