<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Service;

/** @internal */
final class ValidatedProfileUri
{
    public function __construct(
        public readonly string $uri,
        public readonly string $host,
        public readonly int $port,
        public readonly ?string $resolvedIp = null,
        public readonly bool $usesDnsResolution = false,
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
