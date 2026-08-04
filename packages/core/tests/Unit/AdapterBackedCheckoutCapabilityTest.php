<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Adapter\AdapterBackedCheckoutCapability;
use Ucp\Sdk\Adapter\CheckoutAdapterInterface;
use Ucp\Sdk\Adapter\PaymentAwareCheckoutAdapterInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

/**
 * Pins that opting into the completion payment stays optional.
 *
 * The wrapper always satisfies PaymentAwareCheckoutCapabilityInterface, so the
 * executor hands it the full request either way. Whether the payment can be used is
 * the adapter's business, and an adapter written before that interface existed must
 * keep working untouched.
 */
final class AdapterBackedCheckoutCapabilityTest extends TestCase
{
    #[Test]
    public function itForwardsTheCompletionRequestToAnAdapterThatOptedIn(): void
    {
        $adapter = new PaymentAwareCheckoutAdapterFake();
        $capability = new AdapterBackedCheckoutCapability($this->descriptor(), $adapter);

        $capability->completeCheckoutFromRequest(
            new CheckoutCompleteRequest('checkout-1', [new PaymentInstrument('invoice', 'com.example.invoice')]),
            new RequestContext('merchant.example'),
        );

        self::assertSame('com.example.invoice', $adapter->request?->instruments[0]->handlerId);
        self::assertFalse($adapter->completedWithoutRequest);
    }

    #[Test]
    public function itFallsBackToTheOriginalMethodForAnAdapterThatDidNot(): void
    {
        $adapter = new LegacyCheckoutAdapterFake();
        $capability = new AdapterBackedCheckoutCapability($this->descriptor(), $adapter);

        $checkout = $capability->completeCheckoutFromRequest(
            new CheckoutCompleteRequest('checkout-1', [new PaymentInstrument('invoice', 'com.example.invoice')]),
            new RequestContext('merchant.example'),
        );

        self::assertSame('checkout-1', $adapter->completedId, 'The id must still reach the adapter.');
        self::assertSame('checkout-1', $checkout->id);
    }

    #[Test]
    public function itStillExposesTheOriginalCompleteCheckoutMethod(): void
    {
        $adapter = new LegacyCheckoutAdapterFake();
        $capability = new AdapterBackedCheckoutCapability($this->descriptor(), $adapter);

        $capability->completeCheckout('checkout-1', new RequestContext('merchant.example'));

        self::assertSame('checkout-1', $adapter->completedId);
    }

    private function descriptor(): CapabilityDescriptor
    {
        return new CapabilityDescriptor('dev.ucp.shopping.checkout', '2026-04-08', 'spec', 'schema');
    }
}

class LegacyCheckoutAdapterFake implements CheckoutAdapterInterface
{
    public ?string $completedId = null;

    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout
    {
        return $this->checkout('checkout-1');
    }

    public function getCheckout(string $id, RequestContext $context): Checkout
    {
        return $this->checkout($id);
    }

    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout
    {
        return $this->checkout($request->id);
    }

    public function completeCheckout(string $id, RequestContext $context): Checkout
    {
        $this->completedId = $id;

        return $this->checkout($id, CheckoutStatus::Completed);
    }

    public function cancelCheckout(string $id, RequestContext $context): Checkout
    {
        return $this->checkout($id, CheckoutStatus::Canceled);
    }

    protected function checkout(string $id, CheckoutStatus $status = CheckoutStatus::Incomplete): Checkout
    {
        return new Checkout($id, $status, 'EUR', [], []);
    }
}

final class PaymentAwareCheckoutAdapterFake extends LegacyCheckoutAdapterFake implements PaymentAwareCheckoutAdapterInterface
{
    public ?CheckoutCompleteRequest $request = null;

    public bool $completedWithoutRequest = false;

    public function completeCheckout(string $id, RequestContext $context): Checkout
    {
        $this->completedWithoutRequest = true;

        return parent::completeCheckout($id, $context);
    }

    public function completeCheckoutFromRequest(CheckoutCompleteRequest $request, RequestContext $context): Checkout
    {
        $this->request = $request;

        return $this->checkout($request->id, CheckoutStatus::Completed);
    }
}
