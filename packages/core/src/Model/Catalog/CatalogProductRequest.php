<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Catalog;

final class CatalogProductRequest
{
    /**
     * @param list<array<string, mixed>> $selected
     * @param array<string, mixed> $filters
     * @param list<string> $preferences
     * @param array<string, mixed> $context
     * @param array<string, mixed> $signals
     * @param array<string, string> $attribution
     */
    public function __construct(
        public readonly string $id,
        public readonly array $selected = [],
        public readonly array $filters = [],
        public readonly array $preferences = [],
        public readonly array $context = [],
        public readonly array $signals = [],
        public readonly array $attribution = [],
    ) {
    }
}
