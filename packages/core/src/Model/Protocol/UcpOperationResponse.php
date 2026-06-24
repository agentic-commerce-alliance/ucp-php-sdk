<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Protocol;

final readonly class UcpOperationResponse implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private array $payload,
        private UcpEnvelope $envelope,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->payload,
            'ucp' => $this->envelope->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            ...$this->payload,
            'ucp' => $this->envelope->toJsonArray(),
        ];
    }
}
