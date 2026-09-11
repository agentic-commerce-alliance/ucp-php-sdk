<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Profile;

use Ucp\Sdk\Exception\ValidationException;

/**
 * An inclusive version range with a required minimum and an optional maximum.
 *
 * UCP versions are `YYYY-MM-DD`, which sort correctly as strings -- that is the point of the
 * format, and it is why this needs no date parsing. What it does need is to reject anything
 * that is not that shape: a range whose bound is `"latest"` or `"1.0"` compares as a string
 * without complaining and silently answers the wrong question.
 */
final class VersionRange
{
    private const PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    public function __construct(
        public readonly string $min,
        public readonly ?string $max = null,
    ) {
        self::assertVersion($min, 'min');

        if ($max !== null) {
            self::assertVersion($max, 'max');

            if ($max < $min) {
                throw new ValidationException(sprintf('Version range maximum "%s" precedes its minimum "%s".', $max, $min));
            }
        }
    }

    public function contains(string $version): bool
    {
        if ($version < $this->min) {
            return false;
        }

        return $this->max === null || $version <= $this->max;
    }

    /**
     * The overlap of two ranges, or null when they do not overlap.
     *
     * An absent maximum means "no upper bound", so intersecting one open range with another
     * stays open; intersecting an open range with a bounded one takes the bound.
     */
    public function intersect(self $other): ?self
    {
        $min = max($this->min, $other->min);
        $max = match (true) {
            $this->max === null => $other->max,
            $other->max === null => $this->max,
            default => min($this->max, $other->max),
        };

        if ($max !== null && $max < $min) {
            return null;
        }

        return new self($min, $max);
    }

    /**
     * The newest version in this range that the given list also offers, or null when none is.
     *
     * @param list<string> $versions
     */
    public function highestWithin(array $versions): ?string
    {
        $eligible = array_values(array_filter($versions, fn (string $version): bool => $this->contains($version)));

        if ($eligible === []) {
            return null;
        }

        rsort($eligible);

        return $eligible[0];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $payload = ['min' => $this->min];

        if ($this->max !== null) {
            $payload['max'] = $this->max;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload, string $context): self
    {
        $min = $payload['min'] ?? null;
        if (! is_string($min)) {
            throw new ValidationException(sprintf('Version range for "%s" must declare a string "min".', $context));
        }

        $max = $payload['max'] ?? null;
        if ($max !== null && ! is_string($max)) {
            throw new ValidationException(sprintf('Version range for "%s" declares a non-string "max".', $context));
        }

        return new self($min, $max);
    }

    private static function assertVersion(string $version, string $bound): void
    {
        if (preg_match(self::PATTERN, $version) !== 1) {
            throw new ValidationException(sprintf('Version range %s "%s" is not a YYYY-MM-DD version.', $bound, $version));
        }
    }
}
