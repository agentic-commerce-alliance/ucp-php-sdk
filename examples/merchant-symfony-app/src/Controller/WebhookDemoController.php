<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Controller;

use MerchantSymfonyApp\Support\JsonStateStore;
use MerchantSymfonyApp\Support\MerchantSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;
use Ucp\Sdk\Service\OrderWebhookPublisherInterface;

final class WebhookDemoController
{
    public function __construct(
        private readonly OrderWebhookPublisherInterface $dispatcher,
        private readonly JsonStateStore $stateStore,
        private readonly MerchantSettings $settings,
    ) {
    }

    #[Route(path: '/merchant/demo/order-webhooks/dispatch', methods: ['POST'])]
    public function dispatch(Request $request): JsonResponse
    {
        $payload = $request->getContent() !== ''
            ? json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR)
            : [];

        $targetUrl = is_string($payload['target_url'] ?? null) ? $payload['target_url'] : $this->settings->defaultWebhookTarget;
        $orderId = is_string($payload['order_id'] ?? null) ? $payload['order_id'] : 'order-demo-1001';
        $event = is_string($payload['event'] ?? null) ? $payload['event'] : 'order.created';
        $webhookPayload = is_array($payload['payload'] ?? null) ? $payload['payload'] : ['source' => 'merchant-symfony-app'];

        $result = $this->dispatcher->publish(
            $targetUrl,
            new OrderWebhookPayload($event, $orderId, $webhookPayload),
            $request->attributes->get('ucp_request_context'),
        );

        return new JsonResponse([
            'status' => 'queued',
            'target_url' => $targetUrl,
            'order_id' => $orderId,
            'event' => $event,
            'delivery_status' => $result->statusCode,
            'successful' => $result->successful,
        ], 202);
    }

    #[Route(path: '/merchant/demo/webhook-inbox', methods: ['GET'])]
    public function inbox(): JsonResponse
    {
        return new JsonResponse([
            'entries' => $this->stateStore->loadList('merchant_webhook_inbox'),
        ]);
    }

    #[Route(path: '/merchant/demo/webhook-inbox', methods: ['POST'])]
    public function receive(Request $request): JsonResponse
    {
        $payload = $request->getContent() !== ''
            ? json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR)
            : [];

        $this->stateStore->append('merchant_webhook_inbox', [
            'received_at' => gmdate('c'),
            'headers' => $request->headers->all(),
            'payload' => $payload,
        ]);

        return new JsonResponse(['received' => true], 202);
    }
}
