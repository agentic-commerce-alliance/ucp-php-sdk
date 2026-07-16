<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit\Ap2;

use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Ap2\Ap2CheckoutData;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Checkout\PaymentSelection;

final class CheckoutCompleteRequestTest extends TestCase
{
    public function testItCarriesPaymentAndAp2Data(): void
    {
        $instrument = new PaymentInstrument('tokenized', 'com.example.psp', ['token' => 'pay_mandate']);
        $request = new CheckoutCompleteRequest(
            'checkout-1',
            new PaymentSelection([$instrument]),
            new Ap2CheckoutData('checkout_mandate'),
        );

        self::assertSame('checkout-1', $request->id);
        self::assertSame([$instrument], $request->payment?->instruments);
        self::assertSame('checkout_mandate', $request->ap2?->checkoutMandate);
    }

    public function testItDefaultsToNoPaymentAndNoAp2Data(): void
    {
        $request = new CheckoutCompleteRequest('checkout-1');

        self::assertNull($request->payment);
        self::assertNull($request->ap2);
    }
}
