<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Ucp;

use MerchantSymfonyApp\Support\JsonStateStore;
use MerchantSymfonyApp\Support\MerchantSettings;
use MerchantSymfonyApp\Support\UcpModelFactory;
use Ucp\Sdk\Contract\OrderCapabilityInterface;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final class MerchantOrderCapability implements OrderCapabilityInterface
{
    private const COLLECTION = 'merchant_orders';

    public function __construct(
        private readonly JsonStateStore $stateStore,
        private readonly UcpModelFactory $modelFactory,
        private readonly MerchantSettings $settings,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor(
            'dev.ucp.shopping.order',
            '2026-04-08',
            'https://ucp.dev/specification/order/',
            'https://ucp.dev/schemas/shopping/order.json',
            null,
            [
                'country' => $this->settings->country,
                'brand' => $this->settings->brandName,
            ],
        );
    }

    public function getOrder(string $id, RequestContext $context): OrderView
    {
        $record = $this->stateStore->find(self::COLLECTION, $id);
        if ($record === null) {
            return new OrderView(
                $id,
                $this->settings->currency,
                [],
                [],
                [new Message('error', 'Order not found.', 'warning', 'order_not_found')],
            );
        }

        return $this->modelFactory->orderFromArray($record);
    }
}
