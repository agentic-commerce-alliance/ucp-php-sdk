<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use MerchantSymfonyApp\Support\FulfillmentPlanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Checkout\FulfillmentSelection;
use Ucp\Sdk\Model\Common\LineItem;

/**
 * The fulfillment conversation the merchant example holds with a platform.
 *
 * Each step needs the previous one's answer -- the business publishes methods, the platform
 * names a destination, the business prices the options that destination allows, the platform
 * picks one -- so these assert the shape at each step rather than only the end state.
 */
final class MerchantFulfillmentTest extends TestCase
{
    #[Test]
    public function aCheckoutWithoutLineItemsHasNothingToFulfil(): void
    {
        self::assertNull((new FulfillmentPlanner())->plan(null, []));
    }

    #[Test]
    public function theMethodIsPublishedBeforeAnyDestinationIsKnown(): void
    {
        $planned = (new FulfillmentPlanner())->plan(null, [$this->lineItem('li_1')]);

        $method = $planned['methods'][0];
        self::assertSame('shipping', $method['type']);
        self::assertSame(['li_1'], $method['line_item_ids']);
        self::assertArrayNotHasKey('destinations', $method);
        self::assertArrayNotHasKey(
            'groups',
            $method,
            'options price a destination, so they cannot exist before one is named',
        );
    }

    #[Test]
    public function aDestinationIsEchoedWithItsContractType(): void
    {
        // 2026-08-25 requires `type` on destinations in business responses. A platform may omit
        // it on the way in -- it is optional on requests -- so the business fills it in rather
        // than echoing an untagged object back.
        $planned = $this->planWithDestination();

        $destination = $planned['methods'][0]['destinations'][0];
        self::assertSame('shipping_address', $destination['type']);
        self::assertSame('dest_1', $destination['id']);
        self::assertSame('12345', $destination['postal_code'], 'the rest of the address survives');
    }

    #[Test]
    public function optionsAppearOnceADestinationIsSelected(): void
    {
        $group = $this->planWithDestination()['methods'][0]['groups'][0];

        self::assertSame(['li_1'], $group['line_item_ids']);
        self::assertSame(
            ['standard-shipping', 'express-shipping'],
            array_column($group['options'], 'id'),
        );
        self::assertArrayNotHasKey(
            'selected_option_id',
            $group,
            'the platform chooses; offering options is not choosing one',
        );
    }

    #[Test]
    public function anOptionCarriesAStructuredDescriptionAndItsOwnTotals(): void
    {
        $option = $this->planWithDestination()['methods'][0]['groups'][0]['options'][0];

        self::assertSame('Standard', $option['title']);
        // A string here was valid at 2026-04-08 and is not any more.
        self::assertSame(['plain' => 'Arrives in three to five business days.'], $option['description']);
        self::assertSame('fulfillment', $option['totals'][0]['type']);
        self::assertSame(490, $option['totals'][0]['amount'], 'minor units');
    }

    #[Test]
    public function anUnselectedDestinationIsNotChosenOnTheBuyersBehalf(): void
    {
        // Offering exactly one destination is not the same as the platform having picked it.
        $planned = (new FulfillmentPlanner())->plan(
            new FulfillmentSelection('shipping', null, ['methods' => [[
                'type' => 'shipping',
                'destinations' => [['id' => 'dest_1', 'postal_code' => '12345']],
            ]]]),
            [$this->lineItem('li_1')],
        );

        self::assertArrayNotHasKey('selected_destination_id', $planned['methods'][0]);
        self::assertArrayNotHasKey('groups', $planned['methods'][0]);
    }

    #[Test]
    public function nothingIsChargedUntilAnOptionIsSelected(): void
    {
        $planner = new FulfillmentPlanner();

        self::assertSame(0.0, $planner->selectedOptionAmount($this->planWithDestination()));
        self::assertSame(0.0, $planner->selectedOptionAmount(null));
    }

    #[Test]
    public function theSelectedOptionIsWhatIsCharged(): void
    {
        $planner = new FulfillmentPlanner();
        $planned = $this->planWithDestination('express-shipping');

        self::assertSame('express-shipping', $planned['methods'][0]['groups'][0]['selected_option_id']);
        self::assertSame(12.90, $planner->selectedOptionAmount($planned));
    }

    #[Test]
    public function anOptionThisMethodDoesNotOfferIsNotSelected(): void
    {
        // Pickup's option under a shipping method: echoing it back would confirm a choice the
        // business cannot honour, and it would price at zero.
        $planned = $this->planWithDestination('pickup-store');

        self::assertArrayNotHasKey('selected_option_id', $planned['methods'][0]['groups'][0]);
        self::assertSame(0.0, (new FulfillmentPlanner())->selectedOptionAmount($planned));
    }

    #[Test]
    public function pickupOffersItsOwnOptionAndTagsDestinationsAsLocations(): void
    {
        $planned = (new FulfillmentPlanner())->plan(
            new FulfillmentSelection('pickup', null, ['methods' => [[
                'type' => 'pickup',
                'destinations' => [['id' => 'store_1', 'name' => 'Example Store']],
                'selected_destination_id' => 'store_1',
            ]]]),
            [$this->lineItem('li_1')],
        );

        self::assertSame('business_location', $planned['methods'][0]['destinations'][0]['type']);
        self::assertSame(['pickup-store'], array_column($planned['methods'][0]['groups'][0]['options'], 'id'));
    }

    /**
     * @return array<string, mixed>
     */
    private function planWithDestination(?string $selectedOptionId = null): array
    {
        $method = [
            'id' => 'ful_method_1',
            'type' => 'shipping',
            'destinations' => [['id' => 'dest_1', 'postal_code' => '12345', 'address_country' => 'DE']],
            'selected_destination_id' => 'dest_1',
        ];

        if ($selectedOptionId !== null) {
            $method['groups'] = [['id' => 'ful_group_1', 'selected_option_id' => $selectedOptionId]];
        }

        $planned = (new FulfillmentPlanner())->plan(
            new FulfillmentSelection('shipping', null, ['methods' => [$method]]),
            [$this->lineItem('li_1')],
        );

        self::assertNotNull($planned);

        return $planned;
    }

    private function lineItem(string $id): LineItem
    {
        return new LineItem($id, 'Tent', 249.0);
    }
}
