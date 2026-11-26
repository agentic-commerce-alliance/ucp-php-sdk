<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;

final class CheckoutCompleteRequestTest extends TestCase
{
    #[Test]
    public function itSelectsTheInstrumentTheBuyerMarkedAsSelected(): void
    {
        $card = new PaymentInstrument('card', 'com.example.card');
        $invoice = new PaymentInstrument('delegated', 'com.shopware.invoice', selected: true);

        $request = new CheckoutCompleteRequest('checkout-1', [$card, $invoice]);

        self::assertSame($invoice, $request->selectedInstrument());
    }

    #[Test]
    public function itFallsBackToTheFirstInstrumentWhenNoneIsMarkedAsSelected(): void
    {
        $card = new PaymentInstrument('card', 'com.example.card');
        $invoice = new PaymentInstrument('delegated', 'com.shopware.invoice');

        $request = new CheckoutCompleteRequest('checkout-1', [$card, $invoice]);

        self::assertSame($card, $request->selectedInstrument());
    }

    #[Test]
    public function itSelectsNothingWithoutInstruments(): void
    {
        self::assertNull((new CheckoutCompleteRequest('checkout-1'))->selectedInstrument());
    }
}
