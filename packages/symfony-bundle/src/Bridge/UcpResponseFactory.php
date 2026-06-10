<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge;

use Symfony\Component\HttpFoundation\JsonResponse;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

final class UcpResponseFactory
{
    public function __construct(
        private readonly UcpSdkConfiguration $configuration,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function success(array $payload, int $status = 200, array $headers = []): JsonResponse
    {
        if (array_key_exists('ucp', $payload)) {
            throw new \LogicException('Top-level "ucp" is reserved for the protocol envelope.');
        }

        $payload['ucp'] = [
            'version' => $this->configuration->version,
            'status' => 'success',
        ];

        return new JsonResponse($payload, $status, $headers);
    }

    /**
     * @param list<array<string, string>> $messages
     */
    public function error(string $message, int $status = 400, array $messages = []): JsonResponse
    {
        return new JsonResponse([
            'ucp' => [
                'version' => $this->configuration->version,
                'status' => 'error',
            ],
            'messages' => $messages !== [] ? $messages : [[
                'type' => 'error',
                'content' => $message,
            ]],
        ], $status);
    }
}
