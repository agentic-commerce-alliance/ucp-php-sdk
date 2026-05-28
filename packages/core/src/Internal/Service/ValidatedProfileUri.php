<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Service;

/** @internal */
final readonly class ValidatedProfileUri
{
    public function __construct(
        public string $uri,
        public string $host,
        public int $port,
        public ?string $resolvedIp = null,
        public bool $usesDnsResolution = false,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function resolveMap(): array
    {
        if (! $this->usesDnsResolution || $this->resolvedIp === null || $this->resolvedIp === '') {
            return [];
        }

        return [$this->host => $this->resolvedIp];
    }
}
