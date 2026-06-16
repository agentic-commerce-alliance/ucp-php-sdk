<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Profile;

use Ucp\Sdk\Exception\ValidationException;

final class CapabilityDescriptor
{
    /**
     * @param array<string, mixed> $config
     * @param list<string>|null $extends
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $specUrl,
        public readonly string $schemaUrl,
        public readonly ?array $extends = null,
        public readonly array $config = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toProfileEntry(): array
    {
        $entry = [
            'version' => $this->version,
            'spec' => $this->specUrl,
            'schema' => $this->schemaUrl,
        ];

        if ($this->extends !== null && $this->extends !== []) {
            $entry['extends'] = count($this->extends) === 1 ? $this->extends[0] : $this->extends;
        }

        if ($this->config !== []) {
            $entry['config'] = $this->config;
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function fromProfileEntry(string $name, array $entry): self
    {
        $extends = $entry['extends'] ?? null;
        if (is_string($extends)) {
            $extends = [$extends];
        }
        if ($extends !== null && ! is_array($extends)) {
            throw new ValidationException(sprintf('Capability descriptor "%s" field "extends" must be a string or list of strings.', $name));
        }
        if (is_array($extends)) {
            foreach ($extends as $value) {
                if (! is_string($value) || trim($value) === '') {
                    throw new ValidationException(sprintf('Capability descriptor "%s" field "extends" must be a string or list of strings.', $name));
                }
            }
        }

        return new self(
            $name,
            self::requiredString($entry, 'version', $name),
            self::requiredString($entry, 'spec', $name),
            self::requiredString($entry, 'schema', $name),
            is_array($extends) ? array_values($extends) : null,
            is_array($entry['config'] ?? null) ? $entry['config'] : [],
        );
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function requiredString(array $entry, string $field, string $name): string
    {
        $value = $entry[$field] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new ValidationException(sprintf('Capability descriptor "%s" field "%s" must be a non-empty string.', $name, $field));
        }

        return $value;
    }
}
