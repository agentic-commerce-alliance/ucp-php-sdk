<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Profile;

final class PaymentHandlerDescriptor
{
    /**
     * @param list<string> $instrumentSchemas
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly string $specUrl,
        public readonly string $configSchema,
        public readonly array $instrumentSchemas,
        public readonly array $config = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'spec' => $this->specUrl,
            'config_schema' => $this->configSchema,
            'instrument_schemas' => $this->instrumentSchemas,
            'config' => $this->config,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function fromArray(array $entry): self
    {
        return new self(
            (string) ($entry['id'] ?? ''),
            (string) ($entry['name'] ?? ''),
            (string) ($entry['version'] ?? ''),
            (string) ($entry['spec'] ?? ''),
            (string) ($entry['config_schema'] ?? ''),
            array_values(array_map('strval', is_array($entry['instrument_schemas'] ?? null) ? $entry['instrument_schemas'] : [])),
            is_array($entry['config'] ?? null) ? $entry['config'] : [],
        );
    }
}
