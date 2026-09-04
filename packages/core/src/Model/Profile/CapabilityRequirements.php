<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Profile;

use Ucp\Sdk\Exception\ValidationException;

/**
 * What a capability needs in order to work: a protocol version range, and version ranges for
 * the other capabilities it builds on.
 *
 * This is `ucp.json#/$defs/requires`, which has existed since 2026-04-08 and which this SDK
 * published nothing for and read nothing from. A capability that declares it needs
 * `dev.ucp.shopping.cart` at `>= 2026-08-25` and is negotiated against a peer offering the
 * 2026-04-08 cart will be selected, invoked, and fail somewhere further in.
 */
final class CapabilityRequirements
{
    /**
     * @param array<string, VersionRange> $capabilities keyed by capability name
     */
    public function __construct(
        public readonly ?VersionRange $protocol = null,
        public readonly array $capabilities = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->protocol === null && $this->capabilities === [];
    }

    public function allowsProtocol(string $version): bool
    {
        return $this->protocol === null || $this->protocol->contains($version);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [];

        if ($this->protocol !== null) {
            $payload['protocol'] = $this->protocol->toArray();
        }

        if ($this->capabilities !== []) {
            $payload['capabilities'] = array_map(
                static fn (VersionRange $range): array => $range->toArray(),
                $this->capabilities,
            );
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload, string $context): self
    {
        $protocol = null;
        if (isset($payload['protocol'])) {
            if (! is_array($payload['protocol'])) {
                throw new ValidationException(sprintf('Capability descriptor "%s" field "requires.protocol" must be an object.', $context));
            }

            $protocol = VersionRange::fromArray($payload['protocol'], $context . ' requires.protocol');
        }

        $capabilities = [];
        if (isset($payload['capabilities'])) {
            if (! is_array($payload['capabilities'])) {
                throw new ValidationException(sprintf('Capability descriptor "%s" field "requires.capabilities" must be an object.', $context));
            }

            foreach ($payload['capabilities'] as $name => $range) {
                if (! is_string($name) || ! is_array($range)) {
                    throw new ValidationException(sprintf('Capability descriptor "%s" field "requires.capabilities" must map capability names to version ranges.', $context));
                }

                $capabilities[$name] = VersionRange::fromArray($range, $context . ' requires.capabilities.' . $name);
            }
        }

        return new self($protocol, $capabilities);
    }
}
