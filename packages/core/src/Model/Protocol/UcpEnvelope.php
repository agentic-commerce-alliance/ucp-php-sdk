<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Protocol;

use Ucp\Sdk\Enum\UcpCapability;
use Ucp\Sdk\Enum\UcpResponseStatus;

/**
 * @phpstan-type UcpRegistryEntry array{
 *     version: string,
 *     spec?: string,
 *     schema?: string,
 *     id?: string,
 *     config?: array<string, bool|float|int|string|null|list<bool|float|int|string|null>|array<string, bool|float|int|string|null>>,
 *     extends?: string|list<string>,
 *     transport?: string,
 *     endpoint?: string,
 *     available_instruments?: list<array{type: string, constraints?: array<string, bool|float|int|string|null|list<bool|float|int|string|null>|array<string, bool|float|int|string|null>>}>
 * }
 */
final class UcpEnvelope implements \JsonSerializable
{
    /**
     * @param array<string, list<UcpRegistryEntry>> $services
     * @param array<string, list<UcpRegistryEntry>> $capabilities
     * @param array<string, list<UcpRegistryEntry>> $paymentHandlers
     */
    public function __construct(
        public readonly string $version,
        public readonly UcpResponseStatus $status,
        public readonly array $services = [],
        public readonly array $capabilities = [],
        public readonly array $paymentHandlers = [],
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
     * @return array{
     *     version: string,
     *     status: string,
     *     services: array<string, list<UcpRegistryEntry>>,
     *     capabilities: array<string, list<UcpRegistryEntry>>,
     *     payment_handlers: array<string, list<UcpRegistryEntry>>
     * }
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
     * @return array{
     *     version: string,
     *     status: string,
     *     services: array<string, list<UcpRegistryEntry>>|\stdClass,
     *     capabilities: array<string, list<UcpRegistryEntry>>|\stdClass,
     *     payment_handlers: array<string, list<UcpRegistryEntry>>|\stdClass
     * }
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
     * @return array{
     *     version: string,
     *     status: string,
     *     services: array<string, list<UcpRegistryEntry>>|\stdClass,
     *     capabilities: array<string, list<UcpRegistryEntry>>|\stdClass,
     *     payment_handlers: array<string, list<UcpRegistryEntry>>|\stdClass
     * }
     */
    public function toJsonArray(): array
    {
        return $this->jsonSerialize();
    }

    /**
     * @param array<string, list<UcpRegistryEntry>> $registry
     * @return array<string, list<UcpRegistryEntry>>|\stdClass
     */
    private function jsonRegistry(array $registry): array|\stdClass
    {
        return $registry === [] ? new \stdClass() : $registry;
    }
}
