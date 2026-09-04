<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Support;

use Ucp\Sdk\Model\Checkout\FulfillmentSelection;
use Ucp\Sdk\Model\Common\LineItem;

/**
 * Builds the `fulfillment` object a checkout response carries.
 *
 * UCP models fulfillment as a negotiation rather than a field: the business publishes the
 * methods it can fulfil by, the platform names a destination, the business answers with the
 * options and prices that destination makes available, and the platform picks one. Each step
 * needs the previous one's answer, so a response that omits `fulfillment` leaves the platform
 * with nothing to select and the conversation stops.
 *
 * This merchant offers one method at a time and one group within it, which is the shape the
 * spec calls the default configuration: all items ship together.
 */
final class FulfillmentPlanner
{
    public const METHOD_ID = 'ful_method_1';
    public const GROUP_ID = 'ful_group_1';

    /**
     * Options per method type, priced in major units.
     *
     * @var array<string, list<array{id: string, title: string, description: string, carrier?: string, amount: float, days: int}>>
     */
    private const OPTIONS = [
        'shipping' => [
            ['id' => 'standard-shipping', 'title' => 'Standard', 'description' => 'Arrives in three to five business days.', 'carrier' => 'Example Post', 'amount' => 4.90, 'days' => 5],
            ['id' => 'express-shipping', 'title' => 'Express', 'description' => 'Arrives the next business day.', 'carrier' => 'Example Express', 'amount' => 12.90, 'days' => 1],
        ],
        'pickup' => [
            ['id' => 'pickup-store', 'title' => 'Collect in store', 'description' => 'Ready for collection within two hours.', 'amount' => 0.0, 'days' => 0],
        ],
    ];

    /**
     * The destination type each method tags its destinations with.
     *
     * `2026-08-25` made `type` a required discriminator on destinations in business responses,
     * replacing an untagged choice between a postal address and a retail location. A platform
     * sending a destination may omit it -- it is optional on requests -- so the business fills
     * it in on the way back out rather than echoing an untagged object.
     */
    private const DESTINATION_TYPES = [
        'shipping' => 'shipping_address',
        'pickup' => 'business_location',
    ];

    /**
     * @param list<LineItem> $lineItems
     *
     * @return array<string, mixed>|null
     */
    public function plan(?FulfillmentSelection $selection, array $lineItems): ?array
    {
        $lineItemIds = [];
        foreach ($lineItems as $lineItem) {
            $lineItemIds[] = $lineItem->id;
        }

        if ($lineItemIds === []) {
            return null;
        }

        $method = $this->incomingMethod($selection);
        $type = $this->methodType($method);

        $destinations = $this->destinations($method, $type);
        $selectedDestinationId = $this->selectedDestinationId($method, $destinations);

        $planned = [
            'id' => is_string($method['id'] ?? null) && $method['id'] !== '' ? $method['id'] : self::METHOD_ID,
            'type' => $type,
            'line_item_ids' => $lineItemIds,
        ];

        if ($destinations !== []) {
            $planned['destinations'] = $destinations;
        }

        if ($selectedDestinationId !== null) {
            $planned['selected_destination_id'] = $selectedDestinationId;

            // Options depend on where the order is going, so they are only knowable once a
            // destination is settled. Publishing them earlier would be quoting a price for an
            // address nobody has named.
            $planned['groups'] = [$this->group($type, $lineItemIds, $method)];
        }

        return ['methods' => [$planned]];
    }

    /**
     * The order's view of fulfillment, which is not the checkout's.
     *
     * A checkout carries `methods[]` -- the choices still open. An order carries
     * `expectations[]`: what the buyer was told would happen, now that the choosing is over.
     * Reusing the checkout object here would answer a different question than the one asked.
     *
     * @param array<string, mixed>|null $fulfillment the planned checkout fulfillment
     * @param list<LineItem> $lineItems
     *
     * @return array<string, mixed>|null
     */
    public function orderExpectations(?array $fulfillment, array $lineItems = []): ?array
    {
        $method = $fulfillment['methods'][0] ?? null;
        if (! is_array($method)) {
            return null;
        }

        $group = $method['groups'][0] ?? null;
        $selectedOptionId = is_array($group) ? $group['selected_option_id'] ?? null : null;
        if (! is_string($selectedOptionId)) {
            return null;
        }

        $type = $this->methodType($method);
        $option = null;
        foreach (self::OPTIONS[$type] ?? [] as $candidate) {
            if ($candidate['id'] === $selectedOptionId) {
                $option = $candidate;
                break;
            }
        }

        if ($option === null) {
            return null;
        }

        $destination = [];
        foreach (is_array($method['destinations'] ?? null) ? $method['destinations'] : [] as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === ($method['selected_destination_id'] ?? null)) {
                $destination = $candidate;
                break;
            }
        }

        // `destination` is a postal address, so the discriminator and identifier that belong to
        // the checkout's destination contract are not part of it.
        unset($destination['type'], $destination['id']);

        // An expectation references line items with their quantities, not by bare id: what is
        // being promised is a delivery of so many of each, and a split shipment promises some
        // of them now and the rest later.
        $covered = is_array($method['line_item_ids'] ?? null) ? $method['line_item_ids'] : [];
        $expectationItems = [];
        foreach ($lineItems as $lineItem) {
            if (in_array($lineItem->id, $covered, true)) {
                $expectationItems[] = ['id' => $lineItem->id, 'quantity' => $lineItem->quantity];
            }
        }

        return ['expectations' => [[
            'id' => 'exp_1',
            'line_items' => $expectationItems,
            'method_type' => $type,
            'destination' => $destination,
            // The title the buyer chose by, not the internal option id.
            'description' => $option['title'],
            'fulfillable_on' => gmdate('c', time() + $option['days'] * 86400),
        ]]];
    }

    /**
     * The surcharge the selected option adds, in major units.
     *
     * Nothing is charged until an option is chosen: a business that bills for shipping the
     * platform has not selected is quoting a total the buyer never agreed to.
     *
     * @param array<string, mixed>|null $fulfillment
     */
    public function selectedOptionAmount(?array $fulfillment): float
    {
        $method = $fulfillment['methods'][0] ?? null;
        if (! is_array($method)) {
            return 0.0;
        }

        $group = $method['groups'][0] ?? null;
        $selectedOptionId = is_array($group) ? $group['selected_option_id'] ?? null : null;
        if (! is_string($selectedOptionId)) {
            return 0.0;
        }

        foreach (self::OPTIONS[$this->methodType($method)] ?? [] as $option) {
            if ($option['id'] === $selectedOptionId) {
                return $option['amount'];
            }
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $method
     * @param list<string> $lineItemIds
     *
     * @return array<string, mixed>
     */
    private function group(string $type, array $lineItemIds, array $method): array
    {
        $options = [];
        foreach (self::OPTIONS[$type] ?? [] as $option) {
            $entry = [
                'id' => $option['id'],
                'title' => $option['title'],
                // `description` is an object from 2026-08-25, not a string: a business may want
                // to send markup, and a platform has to know whether it is safe to render.
                'description' => ['plain' => $option['description']],
                'totals' => [[
                    'type' => 'fulfillment',
                    'amount' => (int) round($option['amount'] * 100),
                    'currency_code' => 'EUR',
                    'display_text' => number_format($option['amount'], 2, ',', '.') . ' €',
                ]],
                'earliest_fulfillment_time' => gmdate('c', time() + $option['days'] * 86400),
                'latest_fulfillment_time' => gmdate('c', time() + ($option['days'] + 2) * 86400),
            ];

            if (isset($option['carrier'])) {
                $entry['carrier'] = $option['carrier'];
            }

            $options[] = $entry;
        }

        $group = [
            'id' => self::GROUP_ID,
            'line_item_ids' => $lineItemIds,
            'options' => $options,
        ];

        $selected = $this->incomingSelectedOptionId($method);
        if ($selected !== null && $this->offersOption($type, $selected)) {
            $group['selected_option_id'] = $selected;
        }

        return $group;
    }

    /**
     * @return array<string, mixed>
     */
    private function incomingMethod(?FulfillmentSelection $selection): array
    {
        $method = $selection?->extra['methods'][0] ?? null;

        return is_array($method) ? $method : [];
    }

    /**
     * @param array<string, mixed> $method
     */
    private function methodType(array $method): string
    {
        $type = $method['type'] ?? null;

        return is_string($type) && isset(self::OPTIONS[$type]) ? $type : 'shipping';
    }

    /**
     * Echo the destinations the platform named, tagged with their contract type.
     *
     * @param array<string, mixed> $method
     *
     * @return list<array<string, mixed>>
     */
    private function destinations(array $method, string $type): array
    {
        $destinations = [];
        foreach (is_array($method['destinations'] ?? null) ? $method['destinations'] : [] as $index => $destination) {
            if (! is_array($destination)) {
                continue;
            }

            $id = $destination['id'] ?? null;
            $destination['id'] = is_string($id) && $id !== '' ? $id : 'dest_' . ($index + 1);
            $destination['type'] = self::DESTINATION_TYPES[$type];

            $destinations[] = $destination;
        }

        return $destinations;
    }

    /**
     * @param array<string, mixed> $method
     * @param list<array<string, mixed>> $destinations
     */
    private function selectedDestinationId(array $method, array $destinations): ?string
    {
        if ($destinations === []) {
            return null;
        }

        $selected = $method['selected_destination_id'] ?? null;
        foreach ($destinations as $destination) {
            if (is_string($selected) && ($destination['id'] ?? null) === $selected) {
                return $selected;
            }
        }

        // A destination was offered but none chosen. Selecting the only one on the buyer's
        // behalf would be inventing a decision; leaving it unset is what tells the platform
        // there is still a choice to make.
        return null;
    }

    /**
     * @param array<string, mixed> $method
     */
    private function incomingSelectedOptionId(array $method): ?string
    {
        foreach (is_array($method['groups'] ?? null) ? $method['groups'] : [] as $group) {
            $selected = is_array($group) ? $group['selected_option_id'] ?? null : null;
            if (is_string($selected) && $selected !== '') {
                return $selected;
            }
        }

        return null;
    }

    private function offersOption(string $type, string $optionId): bool
    {
        foreach (self::OPTIONS[$type] ?? [] as $option) {
            if ($option['id'] === $optionId) {
                return true;
            }
        }

        return false;
    }
}
