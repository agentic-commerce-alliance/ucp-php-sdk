<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Event\CheckoutRequestReceivedEvent;
use Ucp\Sdk\Event\CheckoutResponsePreparedEvent;
use Ucp\Sdk\Event\PaymentMandateVerificationEvent;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\ProtocolValidatorInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

/**
 * Pins the checkout extension events as a contract rather than as accessors.
 *
 * Each of these events carries a `replace*` mutator, and every one of those mutators was
 * unreached. That is the failure mode worth naming: a listener is handed a mutable event
 * and has to call the mutator for its work to survive, unlike a validator or augmenter
 * whose return value the executor uses. An executor that dispatched the event and then
 * ignored it would look identical from outside, and every test in the suite would still
 * have passed.
 *
 * So each test asserts on what reaches the capability or the caller, not on what the
 * event object holds.
 */
final class ExecutorExtensionEventTest extends TestCase
{
    /**
     * A listener rewriting the incoming request has to change what the capability is
     * asked to create. This is the seam a host uses to inject line items or normalise a
     * buyer before its own code sees them.
     */
    #[Test]
    public function aListenerReplacingTheRequestChangesWhatTheCapabilityReceives(): void
    {
        $capability = new RecordingCheckoutCapability();
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(CheckoutRequestReceivedEvent::class, static function (CheckoutRequestReceivedEvent $event): void {
            $event->replaceRequest(new CheckoutCreateRequest([
                new LineItem('sku-injected', 'Injected', 5.0),
            ]));
        });

        $this->executor($capability, $dispatcher)->execute(new ShoppingOperationRequest(
            'checkout.create',
            ['line_items' => [[
                'item' => ['id' => 'sku-original', 'title' => 'Original', 'price' => 10.0],
                'quantity' => 1,
            ]]],
            new RequestContext('merchant.example'),
        ));

        self::assertNotNull($capability->createRequest);
        self::assertCount(1, $capability->createRequest->lineItems);
        self::assertSame('sku-injected', $capability->createRequest->lineItems[0]->id);
    }

    /**
     * The listener that only reads must not have to replace anything to be harmless. If
     * the executor took the event's request unconditionally that would still hold, so
     * this is not the assertion that carries weight -- it is the one that stops the
     * previous test from passing because replacement is mandatory rather than because it
     * works.
     */
    #[Test]
    public function aListenerThatOnlyObservesLeavesTheRequestAlone(): void
    {
        $capability = new RecordingCheckoutCapability();
        $seen = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(CheckoutRequestReceivedEvent::class, static function (CheckoutRequestReceivedEvent $event) use (&$seen): void {
            $seen = $event->getContext();
        });

        $context = new RequestContext('merchant.example');
        $this->executor($capability, $dispatcher)->execute(new ShoppingOperationRequest(
            'checkout.create',
            ['line_items' => [[
                'item' => ['id' => 'sku-original', 'title' => 'Original', 'price' => 10.0],
                'quantity' => 1,
            ]]],
            $context,
        ));

        self::assertSame($context, $seen, 'The context must reach the listener.');
        self::assertSame('sku-original', $capability->createRequest?->lineItems[0]->id);
    }

    /**
     * The response event fires after every augmenter, so a listener has the final say
     * over what a merchant publishes. Asserting on the returned payload rather than on
     * the event proves the replacement survives to the caller.
     */
    #[Test]
    public function aListenerReplacingTheCheckoutChangesWhatTheCallerReceives(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(CheckoutResponsePreparedEvent::class, static function (CheckoutResponsePreparedEvent $event): void {
            $event->replaceCheckout(new Checkout('checkout-replaced', CheckoutStatus::ReadyForComplete, 'EUR', [], []));
        });

        $result = $this->executor(new RecordingCheckoutCapability(), $dispatcher)->execute(new ShoppingOperationRequest(
            'checkout.get',
            [],
            new RequestContext('merchant.example'),
            'checkout-1',
        ));

        self::assertSame('checkout-replaced', $result->toArray()['id']);
    }

    #[Test]
    public function theResponseEventCarriesTheRequestContext(): void
    {
        $seen = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(CheckoutResponsePreparedEvent::class, static function (CheckoutResponsePreparedEvent $event) use (&$seen): void {
            $seen = $event->getContext();
        });
        $context = new RequestContext('merchant.example');

        $this->executor(new RecordingCheckoutCapability(), $dispatcher)->execute(new ShoppingOperationRequest(
            'checkout.get',
            [],
            $context,
            'checkout-1',
        ));

        self::assertSame($context, $seen);
    }

    /**
     * The mandate event is the only one with no mutator: it announces that an instrument
     * is about to be used so a host can verify a mandate out of band. Both the instrument
     * and the context have to arrive -- a verifier that cannot see which merchant the
     * instrument was presented to cannot check it against anything.
     */
    #[Test]
    public function thePaymentMandateEventCarriesTheInstrumentAndTheContext(): void
    {
        $seen = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(PaymentMandateVerificationEvent::class, static function (PaymentMandateVerificationEvent $event) use (&$seen): void {
            $seen[] = [$event->getInstrument(), $event->getContext()];
        });
        $context = new RequestContext('merchant.example');

        $this->executor(new RecordingCheckoutCapability(), $dispatcher)->execute(new ShoppingOperationRequest(
            'checkout.complete',
            [
                'id' => 'checkout-1',
                'payment' => ['type' => 'card', 'handler_id' => 'com.example.cards'],
            ],
            $context,
            'checkout-1',
        ));

        self::assertCount(1, $seen);
        self::assertInstanceOf(PaymentInstrument::class, $seen[0][0]);
        self::assertSame('com.example.cards', $seen[0][0]->handlerId);
        self::assertSame($context, $seen[0][1]);
    }

    private function executor(CheckoutCapabilityInterface $capability, EventDispatcher $dispatcher): ShoppingOperationExecutor
    {
        return new ShoppingOperationExecutor(
            new SingleCapabilityRegistry($capability),
            $this->createMock(ProtocolValidatorInterface::class),
            new HttpPayloadMapper(),
            [],
            [],
            [],
            $dispatcher,
        );
    }
}

final class RecordingCheckoutCapability implements CheckoutCapabilityInterface
{
    public ?CheckoutCreateRequest $createRequest = null;

    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor('dev.ucp.shopping.checkout', '2026-04-08', 'spec', 'schema');
    }

    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout
    {
        $this->createRequest = $request;

        return $this->checkout('checkout-created');
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
        return $this->checkout($id, CheckoutStatus::Completed);
    }

    public function cancelCheckout(string $id, RequestContext $context): Checkout
    {
        return $this->checkout($id, CheckoutStatus::Canceled);
    }

    private function checkout(string $id, CheckoutStatus $status = CheckoutStatus::Incomplete): Checkout
    {
        return new Checkout($id, $status, 'EUR', [], []);
    }
}
