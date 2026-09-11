<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Adapter\AdapterBackedCartCapability;
use Ucp\Sdk\Adapter\AdapterBackedCatalogCapability;
use Ucp\Sdk\Adapter\AdapterBackedCheckoutCapability;
use Ucp\Sdk\Adapter\AdapterBackedDiscountCapability;
use Ucp\Sdk\Adapter\AdapterBackedIdentityLinkingCapability;
use Ucp\Sdk\Adapter\AdapterBackedOrderCapability;
use Ucp\Sdk\Adapter\AdapterBackedTokenizationCapability;
use Ucp\Sdk\Adapter\CartAdapterInterface;
use Ucp\Sdk\Adapter\CatalogAdapterInterface;
use Ucp\Sdk\Adapter\CheckoutAdapterInterface;
use Ucp\Sdk\Adapter\DiscountAdapterInterface;
use Ucp\Sdk\Adapter\IdentityLinkingAdapterInterface;
use Ucp\Sdk\Adapter\OrderAdapterInterface;
use Ucp\Sdk\Adapter\PaymentAdapterInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Cart\CartCreateRequest;
use Ucp\Sdk\Model\Cart\CartUpdateRequest;
use Ucp\Sdk\Model\Catalog\CatalogLookupRequest;
use Ucp\Sdk\Model\Catalog\CatalogProductRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchRequest;
use Ucp\Sdk\Model\Catalog\Product;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Checkout\DiscountCode;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Identity\OAuthAuthorizationRequest;
use Ucp\Sdk\Model\Identity\OAuthMetadata;
use Ucp\Sdk\Model\Identity\OAuthTokenRequest;
use Ucp\Sdk\Model\Identity\OAuthTokenResponse;
use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

/**
 * Pins what the AdapterBacked* wrappers promise a host application.
 *
 * These classes exist so a project can write a small platform adapter and keep its
 * capability descriptor separate, and the promise they make is that nothing else
 * happens in between. That promise is worth pinning precisely because the code
 * looks too thin to break: return types constrain the shape of what is forwarded,
 * not which argument went where, so a wrapper passing a cart id as a discount code,
 * or handing the adapter its own descriptor, type-checks perfectly.
 *
 * So the assertions are on identity rather than equality. assertSame proves the
 * object the adapter returned is the object the caller received -- an equal copy
 * would mean the wrapper reconstructed it, which is exactly the behaviour these
 * classes must not have.
 *
 * Two of the six do more than forward, and those parts are tested for what they do
 * rather than for delegation.
 */
final class AdapterBackedCapabilityDelegationTest extends TestCase
{
    #[Test]
    public function theCartWrapperForwardsEachOperationUnchanged(): void
    {
        $adapter = new RecordingCartAdapter();
        $capability = new AdapterBackedCartCapability($this->descriptor('dev.ucp.shopping.cart'), $adapter);
        $context = new RequestContext('merchant.example');

        $create = new CartCreateRequest([]);
        self::assertSame($adapter->cart, $capability->createCart($create, $context));
        self::assertSame([$create, $context], $adapter->calls['createCart']);

        self::assertSame($adapter->cart, $capability->getCart('cart-1', $context));
        self::assertSame(['cart-1', $context], $adapter->calls['getCart']);

        $update = new CartUpdateRequest('cart-1', []);
        self::assertSame($adapter->cart, $capability->updateCart($update, $context));
        self::assertSame([$update, $context], $adapter->calls['updateCart']);

        self::assertSame($adapter->cart, $capability->cancelCart('cart-1', $context));
        self::assertSame(['cart-1', $context], $adapter->calls['cancelCart']);
    }

    /**
     * completeCheckout() and the payment opt-in around it are pinned by
     * AdapterBackedCheckoutCapabilityTest, which is about that decision rather than about
     * delegation. The four operations with no decision in them belong here.
     */
    #[Test]
    public function theCheckoutWrapperForwardsTheOperationsThatMakeNoDecision(): void
    {
        $adapter = new RecordingCheckoutAdapter();
        $capability = new AdapterBackedCheckoutCapability($this->descriptor('dev.ucp.shopping.checkout'), $adapter);
        $context = new RequestContext('merchant.example');

        $create = new CheckoutCreateRequest([]);
        self::assertSame($adapter->checkout, $capability->createCheckout($create, $context));
        self::assertSame([$create, $context], $adapter->calls['createCheckout']);

        self::assertSame($adapter->checkout, $capability->getCheckout('checkout-1', $context));
        self::assertSame(['checkout-1', $context], $adapter->calls['getCheckout']);

        $update = new CheckoutUpdateRequest('checkout-1', []);
        self::assertSame($adapter->checkout, $capability->updateCheckout($update, $context));
        self::assertSame([$update, $context], $adapter->calls['updateCheckout']);

        self::assertSame($adapter->checkout, $capability->cancelCheckout('checkout-1', $context));
        self::assertSame(['checkout-1', $context], $adapter->calls['cancelCheckout']);
    }

    #[Test]
    public function theOrderWrapperForwardsTheOrderIdAndContextInThatOrder(): void
    {
        $adapter = new RecordingOrderAdapter();
        $capability = new AdapterBackedOrderCapability($this->descriptor('dev.ucp.shopping.order'), $adapter);
        $context = new RequestContext('merchant.example');

        self::assertSame($adapter->order, $capability->getOrder('order-1', $context));
        self::assertSame(['order-1', $context], $adapter->calls['getOrder']);
    }

    /**
     * The cart id and the discount code are both strings on the wire and are adjacent
     * in the signature, so nothing but this assertion distinguishes forwarding them
     * correctly from swapping them.
     */
    #[Test]
    public function theDiscountWrapperKeepsTheCartIdAndTheCodeApart(): void
    {
        $adapter = new RecordingDiscountAdapter();
        $capability = new AdapterBackedDiscountCapability($this->descriptor('dev.ucp.shopping.discount'), $adapter);
        $context = new RequestContext('merchant.example');
        $code = new DiscountCode('SAVE10');

        self::assertSame($adapter->cart, $capability->applyCartDiscount('cart-1', $code, $context));
        self::assertSame(['cart-1', $code, $context], $adapter->calls['applyCartDiscount']);
    }

    #[Test]
    public function theIdentityLinkingWrapperForwardsEachOperationUnchanged(): void
    {
        $adapter = new RecordingIdentityLinkingAdapter();
        $capability = new AdapterBackedIdentityLinkingCapability(
            $this->descriptor('dev.ucp.common.identity_linking'),
            $adapter,
        );
        $context = new RequestContext('merchant.example');

        self::assertSame($adapter->metadata, $capability->getMetadata($context));
        self::assertSame([$context], $adapter->calls['getMetadata']);

        $authorize = new OAuthAuthorizationRequest('client', 'https://agent.example/cb', 'openid', 'state-1');
        self::assertSame($adapter->authorization, $capability->authorize($authorize, $context));
        self::assertSame([$authorize, $context], $adapter->calls['authorize']);

        $token = new OAuthTokenRequest('authorization_code', 'code-1');
        self::assertSame($adapter->token, $capability->issueToken($token, $context));
        self::assertSame([$token, $context], $adapter->calls['issueToken']);
    }

    #[Test]
    public function theCatalogWrapperForwardsLookupAndProductUnchanged(): void
    {
        $adapter = new RecordingCatalogAdapter();
        $capability = new AdapterBackedCatalogCapability($this->descriptor('dev.ucp.shopping.catalog.lookup'), $adapter);
        $context = new RequestContext('merchant.example');

        $lookup = new CatalogLookupRequest(['sku-1']);
        self::assertSame($adapter->products, $capability->lookup($lookup, $context));
        self::assertSame([$lookup, $context], $adapter->calls['lookup']);

        $product = new CatalogProductRequest('sku-1');
        self::assertSame($adapter->product, $capability->getProduct($product, $context));
        self::assertSame([$product, $context], $adapter->calls['getProduct']);
    }

    /**
     * search() is the one place a wrapper builds something. The adapter returns a bare
     * list and the capability owes the executor a CatalogSearchResponse, so the products
     * must survive the wrapping in order.
     */
    #[Test]
    public function theCatalogWrapperWrapsTheAdaptersProductListInAResponse(): void
    {
        $adapter = new RecordingCatalogAdapter();
        $capability = new AdapterBackedCatalogCapability($this->descriptor('dev.ucp.shopping.catalog.search'), $adapter);
        $context = new RequestContext('merchant.example');
        $request = new CatalogSearchRequest('roses');

        $response = $capability->search($request, $context);

        self::assertSame([$request, $context], $adapter->calls['search']);
        self::assertSame($adapter->products, $response->items);
    }

    /**
     * Documents a limitation rather than an intention: CatalogSearchResponse carries a
     * nextCursor, CatalogAdapterInterface::search() returns a bare list with nowhere to
     * put one, so an adapter that paginates cannot say so through this wrapper. A host
     * needing cursors implements CatalogCapabilityInterface directly. If the adapter
     * interface ever grows a cursor, this assertion is the one that should fail.
     */
    #[Test]
    public function theCatalogWrapperCannotCarryAPaginationCursor(): void
    {
        $capability = new AdapterBackedCatalogCapability(
            $this->descriptor('dev.ucp.shopping.catalog.search'),
            new RecordingCatalogAdapter(),
        );

        $response = $capability->search(new CatalogSearchRequest('roses'), new RequestContext('merchant.example'));

        self::assertNull($response->nextCursor);
    }

    #[Test]
    public function theTokenizationWrapperForwardsTheAdaptersToken(): void
    {
        $adapter = new RecordingPaymentAdapter(['token' => 'tok_123']);
        $capability = new AdapterBackedTokenizationCapability(
            $this->descriptor('dev.ucp.shopping.payment_tokenization'),
            $adapter,
        );
        $context = new RequestContext('merchant.example');
        $instrument = new PaymentInstrument('card', 'com.example.cards');

        self::assertSame(['token' => 'tok_123'], $capability->tokenize($instrument, $context));
        self::assertSame([$instrument, $context], $adapter->calls['tokenize']);
    }

    /**
     * An adapter declining to tokenize returns null, and the capability owes the caller
     * an array. Returning an empty one would read as "tokenized, with nothing in it";
     * the descriptor says which handler declined, which is the part a caller can act on.
     */
    #[Test]
    public function theTokenizationWrapperReportsADeclineNamingTheHandler(): void
    {
        $capability = new AdapterBackedTokenizationCapability(
            $this->descriptor('dev.ucp.shopping.payment_tokenization'),
            new RecordingPaymentAdapter(null),
        );

        $result = $capability->tokenize(
            new PaymentInstrument('card', 'com.example.cards'),
            new RequestContext('merchant.example'),
        );

        self::assertSame(['status' => 'handler_declined', 'handler_id' => 'com.example.cards'], $result);
    }

    /**
     * The descriptor is the wrapper's other reason to exist: it is injected, not derived
     * from the adapter, so the same adapter can be published under a different id or
     * version without touching it.
     */
    #[Test]
    public function everyWrapperPublishesTheDescriptorItWasGiven(): void
    {
        $descriptor = $this->descriptor('dev.ucp.example');

        self::assertSame($descriptor, (new AdapterBackedCartCapability($descriptor, new RecordingCartAdapter()))->describe());
        self::assertSame($descriptor, (new AdapterBackedOrderCapability($descriptor, new RecordingOrderAdapter()))->describe());
        self::assertSame($descriptor, (new AdapterBackedDiscountCapability($descriptor, new RecordingDiscountAdapter()))->describe());
        self::assertSame($descriptor, (new AdapterBackedCatalogCapability($descriptor, new RecordingCatalogAdapter()))->describe());
        self::assertSame($descriptor, (new AdapterBackedCheckoutCapability($descriptor, new RecordingCheckoutAdapter()))->describe());
        self::assertSame($descriptor, (new AdapterBackedTokenizationCapability($descriptor, new RecordingPaymentAdapter(null)))->describe());
        self::assertSame(
            $descriptor,
            (new AdapterBackedIdentityLinkingCapability($descriptor, new RecordingIdentityLinkingAdapter()))->describe(),
        );
    }

    private function descriptor(string $id): CapabilityDescriptor
    {
        return new CapabilityDescriptor($id, '2026-04-08', 'spec', 'schema');
    }
}

final class RecordingCartAdapter implements CartAdapterInterface
{
    /** @var array<string, list<mixed>> */
    public array $calls = [];

    public Cart $cart;

    public function __construct()
    {
        $this->cart = new Cart('cart-1', [], 'EUR');
    }

    public function createCart(CartCreateRequest $request, RequestContext $context): Cart
    {
        $this->calls['createCart'] = [$request, $context];

        return $this->cart;
    }

    public function getCart(string $id, RequestContext $context): Cart
    {
        $this->calls['getCart'] = [$id, $context];

        return $this->cart;
    }

    public function updateCart(CartUpdateRequest $request, RequestContext $context): Cart
    {
        $this->calls['updateCart'] = [$request, $context];

        return $this->cart;
    }

    public function cancelCart(string $id, RequestContext $context): Cart
    {
        $this->calls['cancelCart'] = [$id, $context];

        return $this->cart;
    }
}

final class RecordingCheckoutAdapter implements CheckoutAdapterInterface
{
    /** @var array<string, list<mixed>> */
    public array $calls = [];

    public Checkout $checkout;

    public function __construct()
    {
        $this->checkout = new Checkout('checkout-1', CheckoutStatus::Incomplete, 'EUR', [], []);
    }

    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout
    {
        $this->calls['createCheckout'] = [$request, $context];

        return $this->checkout;
    }

    public function getCheckout(string $id, RequestContext $context): Checkout
    {
        $this->calls['getCheckout'] = [$id, $context];

        return $this->checkout;
    }

    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout
    {
        $this->calls['updateCheckout'] = [$request, $context];

        return $this->checkout;
    }

    public function completeCheckout(string $id, RequestContext $context): Checkout
    {
        $this->calls['completeCheckout'] = [$id, $context];

        return $this->checkout;
    }

    public function cancelCheckout(string $id, RequestContext $context): Checkout
    {
        $this->calls['cancelCheckout'] = [$id, $context];

        return $this->checkout;
    }
}

final class RecordingOrderAdapter implements OrderAdapterInterface
{
    /** @var array<string, list<mixed>> */
    public array $calls = [];

    public OrderView $order;

    public function __construct()
    {
        $this->order = new OrderView('order-1', 'EUR', [], []);
    }

    public function getOrder(string $id, RequestContext $context): OrderView
    {
        $this->calls['getOrder'] = [$id, $context];

        return $this->order;
    }
}

final class RecordingDiscountAdapter implements DiscountAdapterInterface
{
    /** @var array<string, list<mixed>> */
    public array $calls = [];

    public Cart $cart;

    public function __construct()
    {
        $this->cart = new Cart('cart-1', [], 'EUR');
    }

    public function applyCartDiscount(string $cartId, DiscountCode $discount, RequestContext $context): Cart
    {
        $this->calls['applyCartDiscount'] = [$cartId, $discount, $context];

        return $this->cart;
    }
}

final class RecordingCatalogAdapter implements CatalogAdapterInterface
{
    /** @var array<string, list<mixed>> */
    public array $calls = [];

    /** @var list<Product> */
    public array $products;

    public Product $product;

    public function __construct()
    {
        $this->product = new Product('sku-1', 'Roses', 12.5);
        $this->products = [$this->product, new Product('sku-2', 'Tulips', 9.0)];
    }

    public function search(CatalogSearchRequest $request, RequestContext $context): array
    {
        $this->calls['search'] = [$request, $context];

        return $this->products;
    }

    public function lookup(CatalogLookupRequest $request, RequestContext $context): array
    {
        $this->calls['lookup'] = [$request, $context];

        return $this->products;
    }

    public function getProduct(CatalogProductRequest $request, RequestContext $context): Product
    {
        $this->calls['getProduct'] = [$request, $context];

        return $this->product;
    }
}

final class RecordingIdentityLinkingAdapter implements IdentityLinkingAdapterInterface
{
    /** @var array<string, list<mixed>> */
    public array $calls = [];

    public OAuthMetadata $metadata;

    public OAuthTokenResponse $token;

    /** @var array<string, mixed> */
    public array $authorization = ['redirect_uri' => 'https://agent.example/cb?code=code-1'];

    public function __construct()
    {
        $this->metadata = new OAuthMetadata(
            'https://merchant.example',
            'https://merchant.example/authorize',
            'https://merchant.example/token',
        );
        $this->token = new OAuthTokenResponse('access-token-1');
    }

    public function getMetadata(RequestContext $context): OAuthMetadata
    {
        $this->calls['getMetadata'] = [$context];

        return $this->metadata;
    }

    public function authorize(OAuthAuthorizationRequest $request, RequestContext $context): array
    {
        $this->calls['authorize'] = [$request, $context];

        return $this->authorization;
    }

    public function issueToken(OAuthTokenRequest $request, RequestContext $context): OAuthTokenResponse
    {
        $this->calls['issueToken'] = [$request, $context];

        return $this->token;
    }
}

final class RecordingPaymentAdapter implements PaymentAdapterInterface
{
    /** @var array<string, list<mixed>> */
    public array $calls = [];

    /**
     * @param array<string, mixed>|null $tokenizeResult
     */
    public function __construct(private readonly ?array $tokenizeResult)
    {
    }

    public function prepareInstrument(PaymentInstrument $instrument, RequestContext $context): array
    {
        $this->calls['prepareInstrument'] = [$instrument, $context];

        return ['paymentMethodId' => 'pm_1', 'token' => 'tok_1'];
    }

    public function supportsTokenization(): bool
    {
        return $this->tokenizeResult !== null;
    }

    public function tokenize(PaymentInstrument $instrument, RequestContext $context): ?array
    {
        $this->calls['tokenize'] = [$instrument, $context];

        return $this->tokenizeResult;
    }
}
