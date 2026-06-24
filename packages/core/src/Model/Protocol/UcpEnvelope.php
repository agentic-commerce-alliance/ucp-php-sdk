<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Protocol;

use Ucp\Sdk\Enum\UcpCapability;
use Ucp\Sdk\Enum\UcpResponseStatus;

final readonly class UcpEnvelope implements \JsonSerializable
{
    /**
     * @param array<string, list<array<string, mixed>>> $services
     * @param array<string, list<array<string, mixed>>> $capabilities
     * @param array<string, list<array<string, mixed>>> $paymentHandlers
     */
    public function __construct(
        public string $version,
        public UcpResponseStatus $status,
        public array $services = [],
        public array $capabilities = [],
        public array $paymentHandlers = [],
    ) {
    }

    public static function response(string $version, UcpResponseStatus $status, ?UcpCapability $capability = null): self
    {
        return new self(
            $version,
            $status,
            capabilities: $capability === null ? [] : [
                $capability->value => [
                    ['version' => $version],
                ],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'status' => $this->status->value,
            'services' => $this->services,
            'capabilities' => $this->capabilities,
            'payment_handlers' => $this->paymentHandlers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'version' => $this->version,
            'status' => $this->status->value,
            'services' => $this->jsonRegistry($this->services),
            'capabilities' => $this->jsonRegistry($this->capabilities),
            'payment_handlers' => $this->jsonRegistry($this->paymentHandlers),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toJsonArray(): array
    {
        return $this->jsonSerialize();
    }

    /**
     * @param array<string, list<array<string, mixed>>> $registry
     * @return array<string, list<array<string, mixed>>>|\stdClass
     */
    private function jsonRegistry(array $registry): array|\stdClass
    {
        return $registry === [] ? new \stdClass() : $registry;
    }
}
