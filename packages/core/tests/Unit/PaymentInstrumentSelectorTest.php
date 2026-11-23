<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Checkout\PaymentInstrumentSelector;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;

final class PaymentInstrumentSelectorTest extends TestCase
{
    #[Test]
    public function aLaterInstrumentMarkedSelectedWinsOverTheFirst(): void
    {
        $card = new PaymentInstrument('card', 'com.example.card');
        $invoice = new PaymentInstrument('delegated', 'com.shopware.invoice', selected: true);

        self::assertSame($invoice, PaymentInstrumentSelector::select([$card, $invoice]));
    }

    #[Test]
    public function theFirstInstrumentIsReturnedWithoutASelectionAndKeepsSelectedFalse(): void
    {
        $card = new PaymentInstrument('card', 'com.example.card');
        $invoice = new PaymentInstrument('delegated', 'com.shopware.invoice');

        $instrument = PaymentInstrumentSelector::select([$card, $invoice]);

        self::assertSame($card, $instrument);
        self::assertFalse($instrument->selected);
    }

    #[Test]
    public function theFirstOfSeveralSelectedInstrumentsWins(): void
    {
        $card = new PaymentInstrument('card', 'com.example.card');
        $invoice = new PaymentInstrument('delegated', 'com.shopware.invoice', selected: true);
        $wallet = new PaymentInstrument('wallet', 'com.example.wallet', selected: true);

        self::assertSame($invoice, PaymentInstrumentSelector::select([$card, $invoice, $wallet]));
    }

    #[Test]
    public function anEmptyListHasNoInstrument(): void
    {
        self::assertNull(PaymentInstrumentSelector::select([]));
    }
}
