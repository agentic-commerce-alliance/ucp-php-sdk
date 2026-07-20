<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Common\Money;

final class CheckoutTermsFingerprintTest extends TestCase
{
    public function testItIsStableAcrossNonTermChangesAndStatusTransitions(): void
    {
        $base = $this->checkout('USD', 100.0, CheckoutStatus::ReadyForComplete);

        // Same terms, different status and messages must not change the fingerprint.
        $completed = new Checkout(
            $base->id,
            CheckoutStatus::Completed,
            $base->currency,
            $base->lineItems,
            $base->totals,
            [new Message('info', 'Completed.')],
        );

        self::assertSame($base->termsFingerprint(), $completed->termsFingerprint());
    }

    public function testItChangesWhenTheEconomicTermsChange(): void
    {
        $base = $this->checkout('USD', 100.0, CheckoutStatus::ReadyForComplete);

        self::assertNotSame($base->termsFingerprint(), $this->checkout('USD', 150.0, CheckoutStatus::ReadyForComplete)->termsFingerprint());
        self::assertNotSame($base->termsFingerprint(), $this->checkout('EUR', 100.0, CheckoutStatus::ReadyForComplete)->termsFingerprint());
    }

    private function checkout(string $currency, float $amount, CheckoutStatus $status): Checkout
    {
        return new Checkout(
            'chk_1',
            $status,
            $currency,
            [new LineItem('sku-1', 'Tent', $amount, 1, null, [], $currency)],
            [new Money('total', $amount, null, $currency)],
        );
    }
}
