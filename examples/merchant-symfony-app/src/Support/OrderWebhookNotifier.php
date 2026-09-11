<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Support;

use Psr\Log\LoggerInterface;
use Ucp\Sdk\Enum\UcpCapability;
use Ucp\Sdk\Enum\UcpProtocolVersion;
use Ucp\Sdk\Enum\UcpResponseStatus;
use Ucp\Sdk\Model\Protocol\UcpEnvelope;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;
use Ucp\Sdk\Service\OrderWebhookPublisherInterface;

/**
 * Tells the platform an order happened.
 *
 * A business that answers `checkout.complete` and then goes quiet leaves the platform holding
 * an order id and no way to learn what becomes of it: the order is the long-lived thing, and
 * everything after the purchase -- shipment, refund, cancellation -- happens on the business's
 * schedule rather than in response to a request. The event is how that gets back.
 *
 * The destination comes from the platform's own profile rather than from merchant
 * configuration, because it is the platform that knows where its events should go, and a
 * business serving many platforms cannot hold one address for all of them.
 */
final class OrderWebhookNotifier
{
    public const EVENT_ORDER_CREATED = 'order.created';
    public const EVENT_ORDER_UPDATED = 'order.updated';

    public function __construct(
        private readonly OrderWebhookPublisherInterface $publisher,
        private readonly MerchantSettings $settings,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, mixed> $order the stored order record, as `order.get` would answer it
     */
    public function orderCreated(array $order, RequestContext $context): void
    {
        $this->send(self::EVENT_ORDER_CREATED, $order, $this->targetUrl($context), $context);
    }

    /**
     * Something changed after the purchase -- a shipment, a refund, a cancellation.
     *
     * These happen on the business's schedule, with no request to answer, so the target has to
     * come from what was recorded when the order was placed rather than from a profile that is
     * not being fetched.
     *
     * @param array<string, mixed> $order
     */
    public function orderUpdated(array $order, RequestContext $context): void
    {
        $recorded = $order['webhook_target'] ?? null;

        $this->send(
            self::EVENT_ORDER_UPDATED,
            $order,
            is_string($recorded) && $recorded !== '' ? $recorded : $this->targetUrl($context),
            $context,
        );
    }

    /**
     * Where events for this order should go, resolved while the platform is still on the line.
     */
    public function resolveTarget(RequestContext $context): ?string
    {
        return $this->targetUrl($context);
    }

    /**
     * @param array<string, mixed> $order
     */
    private function send(string $event, array $order, ?string $target, RequestContext $context): void
    {
        if ($target === null) {
            return;
        }

        $orderId = is_string($order['id'] ?? null) ? $order['id'] : '';
        unset($order['webhook_target']);

        $payload = new OrderWebhookPayload($event, $orderId, [
            // The delivery is the same current-state snapshot `order.get` would return, not a
            // delta: a receiver that missed an earlier event must not have to reconstruct
            // state from the ones it did get.
            'ucp' => UcpEnvelope::response(
                UcpProtocolVersion::current()->value,
                UcpResponseStatus::Success,
                UcpCapability::Order,
            )->toArray(),
            ...$order,
        ]);

        try {
            $this->publisher->publish($target, $payload, $context);
        } catch (\Throwable $exception) {
            // The order exists and the buyer has paid. Failing the completion response because
            // the platform's receiver is down would turn their outage into ours; the SDK
            // records the delivery attempt either way.
            $this->logger?->warning(sprintf(
                'Order webhook delivery to %s failed: %s',
                $target,
                $exception->getMessage(),
            ), [
                'order_id' => $orderId,
                'target' => $target,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Where the platform said to send order events.
     *
     * `dev.ucp.shopping.order`'s `config.webhook_url` in the platform profile, falling back to
     * the merchant's own configured target so the example still demonstrates delivery when it
     * is driven by something that publishes no profile.
     */
    private function targetUrl(RequestContext $context): ?string
    {
        foreach ($context->platformProfile?->capabilities[UcpCapability::Order->value] ?? [] as $descriptor) {
            $url = $descriptor->config['webhook_url'] ?? null;
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return $this->settings->defaultWebhookTarget !== '' ? $this->settings->defaultWebhookTarget : null;
    }
}
