<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\EventListener;

use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Ucp\Sdk\Model\IdempotencyRecord;
use Ucp\Sdk\Service\IdempotencyServiceInterface;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

/** @internal */
final class IdempotencyResponseListener
{
    public function __construct(
        private readonly IdempotencyServiceInterface $idempotencyService,
        private readonly UcpSdkConfiguration $configuration,
    ) {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }

        $record = $event->getRequest()->attributes->get('ucp_idempotency_record');
        if (! $record instanceof IdempotencyRecord || $record->status !== 'pending') {
            return;
        }

        if ($event->getResponse()->getStatusCode() >= 500) {
            $this->idempotencyService->abort($record);

            return;
        }

        $content = $event->getResponse()->getContent() ?: '{}';
        $payload = [];
        $replayable = true;

        if (strlen($content) > $this->configuration->idempotencyMaxStoredResponseBytes) {
            $replayable = false;
        } else {
            // Decoded to objects, then only the top level flattened to an array. Decoding
            // straight to associative arrays cannot tell `{}` from `[]`, and a replay that
            // turns the envelope's empty `services` map into an empty list is not the response
            // that was sent -- which is the one thing a replay has to be.
            $decoded = json_decode($content, false);
            if ($decoded instanceof \stdClass) {
                $payload = (array) $decoded;
            } else {
                $replayable = false;
            }
        }

        $this->idempotencyService->complete($record, $payload, $event->getResponse()->getStatusCode(), $replayable);
    }
}
