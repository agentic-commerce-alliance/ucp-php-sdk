<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

/**
 * What the buyer has consented to, keyed by purpose.
 *
 * This was a single boolean with a timestamp, which could express "the buyer agreed" and
 * nothing about what to. 2026-08-25 restructured `consent` into a map keyed by reverse-DNS
 * identifiers (`dev.ucp.consent.*`), each carrying whether it is granted, which party asserted
 * that, a description, and optional per-segment refinements. A business that needs to know
 * whether it may email someone cannot answer that from one boolean.
 */
final class BuyerConsent
{
    public const PURPOSE_MARKETING = 'dev.ucp.consent.marketing';
    public const PURPOSE_ANALYTICS = 'dev.ucp.consent.analytics';
    public const PURPOSE_PREFERENCES = 'dev.ucp.consent.preferences';
    public const PURPOSE_SALE_OR_SHARING = 'dev.ucp.consent.sale_or_sharing';

    /**
     * The 2026-04-08 boolean flags, mapped onto the purposes that replaced them.
     *
     * `sale_of_data` became `sale_or_sharing`: CCPA covers sharing for cross-context advertising
     * as well as sale, and the old name only named half of what it governed.
     */
    private const LEGACY_FLAGS = [
        'marketing' => self::PURPOSE_MARKETING,
        'analytics' => self::PURPOSE_ANALYTICS,
        'preferences' => self::PURPOSE_PREFERENCES,
        'sale_of_data' => self::PURPOSE_SALE_OR_SHARING,
    ];

    /**
     * @param array<string, ConsentPurpose> $purposes keyed by reverse-DNS purpose identifier
     */
    public function __construct(
        public readonly array $purposes = [],
    ) {
    }

    /**
     * Whether a named purpose is granted, optionally within a segment.
     *
     * An unknown purpose is not granted. Absence is not consent, and a caller asking about a
     * purpose the buyer was never presented with should get the safe answer rather than an
     * exception it has to handle at every call site.
     */
    public function granted(string $purpose, ?string $segment = null): bool
    {
        return ($this->purposes[$purpose] ?? null)?->grantedFor($segment) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (ConsentPurpose $purpose): array => $purpose->toArray(),
            $this->purposes,
        );
    }

    /**
     * Keys are typed as array-key rather than string on purpose: this parses decoded JSON, and
     * a purpose identifier that looks numeric arrives as an int key.
     *
     * @param array<array-key, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $purposes = [];
        foreach ($payload as $name => $purpose) {
            if (! is_string($name)) {
                continue;
            }

            if (is_array($purpose)) {
                $parsed = ConsentPurpose::fromArray($purpose);
                if ($parsed !== null) {
                    $purposes[$name] = $parsed;
                }

                continue;
            }

            // A 2026-04-08 boolean flag. It carries no source, so it is attributed to the
            // platform that sent it -- that is what the flag actually was: a platform relaying
            // a buyer decision it made no claim to have recorded itself.
            if (is_bool($purpose) && isset(self::LEGACY_FLAGS[$name])) {
                $purposes[self::LEGACY_FLAGS[$name]] = new ConsentPurpose(
                    $purpose,
                    ConsentPurpose::SOURCE_PLATFORM,
                    sprintf('Migrated from the %s consent flag.', $name),
                );
            }
        }

        return new self($purposes);
    }
}
