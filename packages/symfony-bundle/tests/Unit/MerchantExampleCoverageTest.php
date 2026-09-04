<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use MerchantSymfonyApp\Support\JsonStateStore;
use MerchantSymfonyApp\Support\MerchantSettings;
use MerchantSymfonyApp\Support\PriceCalculator;
use MerchantSymfonyApp\Support\ProductCatalog;
use MerchantSymfonyApp\Support\UcpModelFactory;
use MerchantSymfonyApp\Ucp\MerchantCartCapability;
use MerchantSymfonyApp\Ucp\MerchantCatalogCapability;
use MerchantSymfonyApp\Ucp\MerchantOrderCapability;
use MerchantSymfonyApp\Ucp\MerchantOrderWebhookEnricher;
use MerchantSymfonyApp\Ucp\MerchantPaymentHandler;
use MerchantSymfonyApp\Ucp\MerchantPaymentMandateVerifier;
use MerchantSymfonyApp\Ucp\MerchantTokenizationCapability;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Cart\CartCreateRequest;
use Ucp\Sdk\Model\Cart\CartUpdateRequest;
use Ucp\Sdk\Model\Catalog\CatalogLookupRequest;
use Ucp\Sdk\Model\Catalog\CatalogProductRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchRequest;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;

final class MerchantExampleCoverageTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/ucp-merchant-example-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    #[Test]
    public function itEnrichesWebhookPayloadsWithMerchantMetadata(): void
    {
        $enricher = new MerchantOrderWebhookEnricher($this->settings());

        $payload = $enricher->enrich(new OrderWebhookPayload('order.created', 'ord_1', [
            'source' => 'test',
            'nested' => ['kept' => true],
        ]), $this->context());

        self::assertSame('order.created', $payload->event);
        self::assertSame('ord_1', $payload->orderId);
        self::assertSame('test', $payload->payload['source']);
        self::assertSame(['kept' => true], $payload->payload['nested']);
        self::assertSame(['brand' => 'Acme Outdoor', 'country' => 'DE'], $payload->payload['merchant']);
    }

    #[Test]
    public function itVerifiesMerchantPaymentMandates(): void
    {
        $verifier = new MerchantPaymentMandateVerifier();

        $verifier->verify(new PaymentInstrument('card', 'merchant.card', ['token' => 'tok_123']), $this->context());
        $verifier->verify(new PaymentInstrument('card', 'merchant.card', ['card_last4' => '4242']), $this->context());

        self::expectNotToPerformAssertions();
    }

    #[Test]
    public function itRejectsInvalidMerchantPaymentMandates(): void
    {
        $verifier = new MerchantPaymentMandateVerifier();

        try {
            $verifier->verify(new PaymentInstrument('card', 'other.handler', ['card_last4' => '4242']), $this->context());
            self::fail('Expected unsupported handler validation exception.');
        } catch (ValidationException $exception) {
            self::assertSame('Unsupported payment handler for merchant example.', $exception->getMessage());
        }

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Missing merchant payment credential.');

        $verifier->verify(new PaymentInstrument('card', 'merchant.card', []), $this->context());
    }

    #[Test]
    public function itDescribesPreparesAndTokenizesMerchantPayments(): void
    {
        $handler = new MerchantPaymentHandler($this->settings());

        self::assertSame('merchant.card', $handler->id());
        self::assertTrue($handler->supportsTokenization());

        $descriptor = $handler->describe($this->context());
        self::assertSame('merchant.card', $descriptor->id);
        self::assertSame('Acme Outdoor', $descriptor->config['merchant']);
        self::assertSame(['visa', 'mastercard', 'amex'], $descriptor->config['supported_networks']);

        $prepared = $handler->prepareInstrument(new PaymentInstrument('card', 'merchant.card', []), $this->context());
        self::assertSame('prepared_0000', $prepared['token']);
        self::assertSame('0000', $prepared['displayLast4'] ?? null);

        $token = $handler->tokenize(new PaymentInstrument('card', 'merchant.card', ['card_last4' => '4242']), $this->context());
        self::assertIsArray($token);
        self::assertSame('merchant.card', $token['handler_id']);
        self::assertStringStartsWith('tok_4242_', $token['token']);
        self::assertNull($handler->tokenize(new PaymentInstrument('card', 'other.handler', ['card_last4' => '4242']), $this->context()));
    }

    #[Test]
    public function itTokenizesThroughMerchantTokenizationCapability(): void
    {
        $capability = new MerchantTokenizationCapability();

        $descriptor = $capability->describe();
        self::assertSame('dev.ucp.shopping.payment_tokenization', $descriptor->name);
        self::assertSame(['merchant.card'], $descriptor->config['handler_ids']);

        $token = $capability->tokenize(new PaymentInstrument('card', 'merchant.card', ['card_last4' => '1234']), $this->context());
        self::assertSame('1234', $token['card_last4']);
        self::assertSame('merchant.card', $token['handler_id']);
        self::assertStringStartsWith('tok_1234_', $token['token']);

        $fallback = $capability->tokenize(new PaymentInstrument('card', 'merchant.card', []), $this->context());
        self::assertSame('0000', $fallback['card_last4']);
    }

    #[Test]
    public function itMapsMerchantCatalogResultsAndUnknownProducts(): void
    {
        $capability = new MerchantCatalogCapability(new ProductCatalog());

        $descriptor = $capability->describe();
        self::assertSame('dev.ucp.shopping.catalog.search', $descriptor->name);
        self::assertSame('EUR', $descriptor->config['price_currency']);

        $search = $capability->search(new CatalogSearchRequest('tent', 5), $this->context());
        self::assertSame('tent-4p', $search->items[0]->id);

        $lookup = $capability->lookup(new CatalogLookupRequest(['stove-lite', 'missing']), $this->context());
        self::assertCount(1, $lookup);
        self::assertSame('stove-lite', $lookup[0]->id);

        $unknown = $capability->getProduct(new CatalogProductRequest('missing-sku'), $this->context());
        self::assertSame('missing-sku', $unknown->id);
        self::assertSame('Unknown product', $unknown->title);
        self::assertSame(0.0, $unknown->price);
    }

    #[Test]
    public function itCoversMerchantCartStateBranches(): void
    {
        $capability = $this->cartCapability();
        $context = $this->context();

        // An unknown cart is not an empty cart carrying a warning. That answer is a cart, so
        // an agent reading the status rather than the messages adds items to something the
        // business does not have -- and it does not validate either, because a fabricated
        // cart has no totals, so the caller used to receive `invalid_request` about our own
        // response instead of `not_found` about their id.
        try {
            $capability->getCart('missing-cart', $context);
            self::fail('An unknown cart id must not resolve to a cart.');
        } catch (ResourceNotFoundException $exception) {
            self::assertStringContainsString('missing-cart', $exception->getMessage());
        }

        $created = $capability->createCart(new CartCreateRequest([
            new LineItem('tent-4p', 'Placeholder', 1.0, 1),
        ]), $context);
        self::assertStringStartsWith('cart_', $created->id);
        self::assertSame('Summit 4P Tent', $created->lineItems[0]->title);

        $updated = $capability->updateCart(new CartUpdateRequest($created->id, [
            new LineItem('stove-lite', 'Placeholder', 1.0, 2),
        ]), $context);
        self::assertSame('Trail Lite Stove', $updated->lineItems[0]->title);
        self::assertSame(2, $updated->lineItems[0]->quantity);

        $canceled = $capability->cancelCart($created->id, $context);
        $lastMessage = null;
        foreach ($canceled->messages as $message) {
            $lastMessage = $message;
        }

        self::assertSame($created->id, $canceled->id);
        self::assertNotNull($lastMessage);
        self::assertSame('Cart canceled and removed from merchant state.', $lastMessage->content);
    }

    #[Test]
    public function itReturnsNotFoundOrderMessages(): void
    {
        $capability = new MerchantOrderCapability($this->stateStore(), new UcpModelFactory(), $this->settings());

        $order = $capability->getOrder('missing-order', $this->context());

        self::assertSame('missing-order', $order->id);
        self::assertSame('order_not_found', $order->messages[0]->code);
    }

    private function cartCapability(): MerchantCartCapability
    {
        return new MerchantCartCapability(
            $this->stateStore(),
            new PriceCalculator(new ProductCatalog(), $this->settings()),
            new UcpModelFactory(),
            $this->settings(),
        );
    }

    private function stateStore(): JsonStateStore
    {
        return new JsonStateStore($this->projectDir);
    }

    private function settings(): MerchantSettings
    {
        return new MerchantSettings(
            'https://merchant.example',
            'Acme Outdoor',
            'EUR',
            'DE',
            'https://default.example/webhook',
        );
    }

    private function context(): RequestContext
    {
        return new RequestContext('merchant.example');
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
