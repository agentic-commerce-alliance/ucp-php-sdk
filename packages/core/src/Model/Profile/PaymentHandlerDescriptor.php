<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Profile;

use Ucp\Sdk\Exception\ValidationException;

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
        $instrumentSchemas = $entry['instrument_schemas'] ?? null;
        if (! is_array($instrumentSchemas) || ! array_is_list($instrumentSchemas)) {
            throw new ValidationException('Payment handler field "instrument_schemas" must be a list of strings.');
        }

        foreach ($instrumentSchemas as $schema) {
            if (! is_string($schema) || trim($schema) === '') {
                throw new ValidationException('Payment handler field "instrument_schemas" must be a list of strings.');
            }
        }

        return new self(
            self::requiredString($entry, 'id'),
            self::requiredString($entry, 'name'),
            self::requiredString($entry, 'version'),
            self::requiredString($entry, 'spec'),
            self::requiredString($entry, 'config_schema'),
            $instrumentSchemas,
            is_array($entry['config'] ?? null) ? $entry['config'] : [],
        );
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function requiredString(array $entry, string $field): string
    {
        $value = $entry[$field] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new ValidationException(sprintf('Payment handler field "%s" must be a non-empty string.', $field));
        }

        return $value;
    }
}
