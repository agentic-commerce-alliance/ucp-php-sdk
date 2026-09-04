<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Controller;

use MerchantSymfonyApp\Support\JsonStateStore;
use MerchantSymfonyApp\Support\MerchantSettings;
use MerchantSymfonyApp\Support\OrderWebhookNotifier;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Enum\SignaturePolicy;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\RequestContext;

/**
 * Drives post-order events that would otherwise take days.
 *
 * Shipping, refunds and returns happen on the business's own schedule, so there is no request
 * a caller can make to observe them. Anything testing that a business announces them therefore
 * needs a way to say "pretend the parcel left" -- which is what this is.
 *
 * Deliberately outside `/ucp/`: it is not part of the protocol, it is a fixture. A real
 * merchant drives these from its warehouse system, and shipping this route in production would
 * let anyone holding the secret fabricate order events.
 */
final class SimulationController
{
    private const ORDER_COLLECTION = 'merchant_orders';

    public function __construct(
        private readonly JsonStateStore $stateStore,
        private readonly MerchantSettings $settings,
        private readonly OrderWebhookNotifier $orderWebhookNotifier,
    ) {
    }

    #[Route('/testing/simulate-shipping/{orderId}', name: 'merchant_simulate_shipping', methods: ['POST'])]
    public function simulateShipping(string $orderId, Request $request): JsonResponse
    {
        $secret = $this->simulationSecret();

        // Compared in constant time, and a missing header fails the same way a wrong one does:
        // answering differently would say whether a secret is configured at all.
        $provided = (string) $request->headers->get('Simulation-Secret', '');
        if ($secret === '' || ! hash_equals($secret, $provided)) {
            return new JsonResponse(['error' => 'Invalid simulation secret.'], 403);
        }

        $order = $this->stateStore->find(self::ORDER_COLLECTION, $orderId);
        if ($order === null) {
            return new JsonResponse(['error' => 'Order not found.'], 404);
        }

        $order = $this->withShipment($order);
        $this->stateStore->put(self::ORDER_COLLECTION, $orderId, $order);
        $this->orderWebhookNotifier->orderUpdated($order, $this->context());

        return new JsonResponse(['status' => 'shipped', 'order_id' => $orderId]);
    }

    /**
     * Append the shipment to the order's fulfillment event log.
     *
     * `events[]` is append-only and separate from `expectations[]` on purpose: what was
     * promised and what actually happened are different claims, and a business that overwrites
     * the promise with the outcome loses the ability to say it shipped late.
     *
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function withShipment(array $order): array
    {
        $fulfillment = is_array($order['fulfillment'] ?? null) ? $order['fulfillment'] : [];
        $expectation = $fulfillment['expectations'][0] ?? null;

        $events = is_array($fulfillment['events'] ?? null) ? $fulfillment['events'] : [];
        $events[] = [
            'id' => 'evt_' . count($events),
            'type' => 'shipped',
            'line_items' => is_array($expectation['line_items'] ?? null) ? $expectation['line_items'] : [],
            'occurred_at' => gmdate('c'),
        ];

        $fulfillment['events'] = $events;
        $order['fulfillment'] = $fulfillment;

        return $order;
    }

    /**
     * A context for a delivery nobody asked for.
     *
     * The dispatcher needs the business's own configuration to sign with and to name itself in
     * `UCP-Agent`; there is no inbound request here to derive one from.
     */
    private function context(): RequestContext
    {
        return new RequestContext(
            (string) (parse_url($this->settings->baseUri, PHP_URL_HOST) ?: 'localhost'),
            runtimeConfiguration: new RuntimeConfiguration(
                \Ucp\Sdk\Enum\UcpProtocolVersion::current()->value,
                $this->settings->baseUri,
                SignaturePolicy::Log,
            ),
        );
    }

    private function simulationSecret(): string
    {
        // getenv() as well as the superglobals: whether the environment reaches `$_ENV`
        // depends on `variables_order`, and under `php -S` it commonly does not.
        $secret = $_ENV['SIMULATION_SECRET'] ?? $_SERVER['SIMULATION_SECRET'] ?? getenv('SIMULATION_SECRET');

        return is_string($secret) ? $secret : '';
    }
}
