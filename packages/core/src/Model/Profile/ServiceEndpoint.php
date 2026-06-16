<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Profile;

use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Exception\ValidationException;

final class ServiceEndpoint
{
    public function __construct(
        public readonly Transport $transport,
        public readonly string $endpoint,
        public readonly string $version,
        public readonly string $specUrl,
        public readonly ?string $schemaUrl = null,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $payload = [
            'transport' => $this->transport->value,
            'endpoint' => $this->endpoint,
            'version' => $this->version,
            'spec' => $this->specUrl,
        ];

        if ($this->schemaUrl !== null && $this->schemaUrl !== '') {
            $payload['schema'] = $this->schemaUrl;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function fromArray(array $entry): self
    {
        $transport = self::requiredString($entry, 'transport');

        return new self(
            Transport::from($transport),
            self::requiredString($entry, 'endpoint'),
            self::requiredString($entry, 'version'),
            self::requiredString($entry, 'spec'),
            self::optionalString($entry, 'schema'),
        );
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function requiredString(array $entry, string $field): string
    {
        $value = $entry[$field] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new ValidationException(sprintf('Service endpoint field "%s" must be a non-empty string.', $field));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function optionalString(array $entry, string $field): ?string
    {
        $value = $entry[$field] ?? null;
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new ValidationException(sprintf('Service endpoint field "%s" must be a non-empty string when present.', $field));
        }

        return $value;
    }
}
