<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Profile;

final readonly class CapabilityDescriptor
{
    /**
     * @param array<string, mixed> $config
     * @param list<string>|null $extends
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $specUrl,
        public string $schemaUrl,
        public ?array $extends = null,
        public array $config = [],
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

        return new self(
            $name,
            (string) ($entry['version'] ?? ''),
            (string) ($entry['spec'] ?? ''),
            (string) ($entry['schema'] ?? ''),
            is_array($extends) ? array_values(array_map('strval', $extends)) : null,
            is_array($entry['config'] ?? null) ? $entry['config'] : [],
        );
    }
}
