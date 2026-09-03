<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

/**
 * One thing a buyer has or has not consented to, and who says so.
 *
 * `source` is the part worth not losing: it names which party asserted the state. A business
 * that recorded consent itself and a platform that is relaying what a buyer clicked are making
 * different claims, and only the first is evidence the business can stand behind later.
 *
 * `segments` refines a purpose — the same purpose scoped to a channel or a vendor — and a
 * segment's `granted` overrides the parent's for that scope, which is what makes
 * "marketing, but not SMS" expressible.
 */
final class ConsentPurpose
{
    public const SOURCE_BUSINESS = 'business';
    public const SOURCE_PLATFORM = 'platform';

    /**
     * @param array<string, ConsentPurpose> $segments
     * @param list<array<string, mixed>> $links
     */
    public function __construct(
        public readonly bool $granted,
        public readonly string $source,
        public readonly string $description,
        public readonly array $segments = [],
        public readonly array $links = [],
    ) {
    }

    /**
     * Whether consent covers a given segment, honouring the segment override.
     */
    public function grantedFor(?string $segment = null): bool
    {
        if ($segment === null || ! isset($this->segments[$segment])) {
            return $this->granted;
        }

        return $this->segments[$segment]->granted;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'granted' => $this->granted,
            'source' => $this->source,
            'description' => $this->description,
        ];

        if ($this->links !== []) {
            $payload['links'] = $this->links;
        }

        if ($this->segments !== []) {
            $payload['segments'] = array_map(
                static fn (self $segment): array => $segment->toArray(),
                $this->segments,
            );
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): ?self
    {
        $granted = $payload['granted'] ?? null;
        $source = $payload['source'] ?? null;
        $description = $payload['description'] ?? null;

        // All three are required. A purpose missing `source` cannot be acted on -- it does not
        // say who asserted it -- so it is dropped rather than defaulted to one of the parties.
        if (! is_bool($granted) || ! is_string($source) || ! is_string($description)) {
            return null;
        }

        $segments = [];
        foreach (is_array($payload['segments'] ?? null) ? $payload['segments'] : [] as $name => $segment) {
            if (is_string($name) && is_array($segment)) {
                $parsed = self::fromArray($segment);
                if ($parsed !== null) {
                    $segments[$name] = $parsed;
                }
            }
        }

        $links = [];
        foreach (is_array($payload['links'] ?? null) ? $payload['links'] : [] as $link) {
            if (is_array($link)) {
                $links[] = $link;
            }
        }

        return new self($granted, $source, $description, $segments, $links);
    }
}
