<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Ucp\Sdk\Contract\OrderWebhookEnricherInterface;
use Ucp\Sdk\Exception\UcpException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Internal\Service\DefaultOrderWebhookDispatcher;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Repository\TenantAwareManagedSigningKeyRepositoryInterface;
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
            new MockHttpClient(static fn (string $method, string $url, array $options): MockResponse => new MockResponse('accepted', [
                'http_code' => 202,
                'response_headers' => ['X-Webhook-Id: demo-1'],
            ])),
            [$enricher],
            new EventDispatcher(),
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
            new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'network down'])),
            [],
            new EventDispatcher(),
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
            new MockHttpClient(),
            [],
            new EventDispatcher(),
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
            new MockHttpClient(static function () use (&$requestAttempted): MockResponse {
                $requestAttempted = true;

                return new MockResponse('', ['http_code' => 204]);
            }),
            [],
            new EventDispatcher(),
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
            new MockHttpClient(static fn (): MockResponse => new MockResponse(str_repeat('x', 262145), [
                'http_code' => 202,
                'response_headers' => ['Content-Length: 262145'],
            ])),
            [],
            new EventDispatcher(),
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
            new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 204])),
            [],
            new EventDispatcher(),
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
