<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Profile;

use Ucp\Sdk\Enum\Transport;

final readonly class ServiceEndpoint
{
    public function __construct(
        public Transport $transport,
        public string $endpoint,
        public string $version,
        public string $specUrl,
        public ?string $schemaUrl = null,
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
        return new self(
            Transport::from((string) ($entry['transport'] ?? Transport::Rest->value)),
            (string) ($entry['endpoint'] ?? ''),
            (string) ($entry['version'] ?? ''),
            (string) ($entry['spec'] ?? ''),
            isset($entry['schema']) && is_string($entry['schema']) && $entry['schema'] !== '' ? $entry['schema'] : null,
        );
    }
}
