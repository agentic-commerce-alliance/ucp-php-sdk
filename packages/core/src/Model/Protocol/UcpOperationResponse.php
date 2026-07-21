<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Protocol;

final class UcpOperationResponse implements \JsonSerializable
{
    public function __construct(
        private readonly UcpOperationPayload $payload,
        private readonly UcpEnvelope $envelope,
    ) {
    }

    /**
     * @return array<string, bool|float|int|string|null|\stdClass|array<array-key, bool|float|int|string|null|\stdClass|array<array-key, bool|float|int|string|null|\stdClass|array<array-key, bool|float|int|string|null>>>>
     */
    public function toArray(): array
    {
        return [
            ...$this->payload->toArray(),
            'ucp' => $this->envelope->toArray(),
        ];
    }

    /**
     * @return array<string, bool|float|int|string|null|\stdClass|array<array-key, bool|float|int|string|null|\stdClass|array<array-key, bool|float|int|string|null|\stdClass|array<array-key, bool|float|int|string|null>>>>
     */
    public function jsonSerialize(): array
    {
        return [
            ...$this->payload->jsonSerialize(),
            'ucp' => $this->envelope->toJsonArray(),
        ];
    }
}
