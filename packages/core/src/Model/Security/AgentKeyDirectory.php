<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Security;

/**
 * The keys an agent publishes at its `Signature-Agent` URL.
 *
 * A JWK Set and nothing else. It is deliberately not a `PlatformProfile`: a profile describes a
 * peer's capabilities, versions and services, and a key directory makes none of those claims.
 * Reusing the profile type here would have meant inventing a protocol version for a document
 * that has none, and storing that invention in a cache other code reads.
 */
final class AgentKeyDirectory
{
    /**
     * @param list<PublicSigningKey> $keys
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $keys = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'keys' => array_map(static fn (PublicSigningKey $key): array => $key->toJwk(), $this->keys),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(string $uri, array $payload): self
    {
        $entries = $payload['keys'] ?? null;

        $keys = [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            // One key type this SDK cannot verify with must not discard the rest of the set --
            // the same rule a platform profile follows, and the reason `tryFromJwk` exists.
            $key = PublicSigningKey::tryFromJwk($entry);
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return new self($uri, $keys);
    }
}
