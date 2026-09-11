<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Negotiation;

use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\VersionRange;

/**
 * Decides which of a peer's capability entries survive negotiation.
 *
 * Three things are checked, and only the first was checked before: that both sides know the
 * capability by name, that they know it at the same version, and that whatever each side
 * declared in `requires` is satisfied by what actually survived.
 *
 * @internal
 */
final class NegotiatedCapabilityResolver
{
    /**
     * @param array<string, list<CapabilityDescriptor>> $offered entries the peer published, restricted to names we implement
     * @param array<string, CapabilityDescriptor> $local
     *
     * @return array<string, list<CapabilityDescriptor>>
     */
    public function resolve(array $offered, array $local, ?string $protocolVersion): array
    {
        $surviving = [];

        foreach ($offered as $name => $entries) {
            $localDescriptor = $local[$name] ?? null;
            if ($localDescriptor === null) {
                continue;
            }

            // Capability versions are contract revisions, not release numbers: nothing in the
            // schema promises that a capability at one version can serve a peer expecting
            // another. Matching by name alone -- which is what happened before -- selects the
            // capability, invokes it, and fails somewhere further in with an error about a
            // field rather than about the version. `requires` is how a peer expresses
            // tolerance for a range; a bare `version` expresses none.
            $matching = array_values(array_filter(
                $entries,
                fn (CapabilityDescriptor $entry): bool => $entry->version === $localDescriptor->version
                    && $this->protocolSatisfied($entry, $protocolVersion)
                    && $this->protocolSatisfied($localDescriptor, $protocolVersion),
            ));

            if ($matching !== []) {
                $surviving[$name] = $matching;
            }
        }

        return $this->applyDependencies($surviving, $local);
    }

    /**
     * Drop entries whose dependencies did not survive, repeatedly, until nothing more drops.
     *
     * One pass is not enough and the failure is silent. If A extends B and B extends C and the
     * peer does not offer C, a single pass drops B -- but it tests A against the name set as it
     * was *before* B was dropped, so A survives depending on a capability that is no longer
     * there. Each removal can invalidate another, so this has to run to a fixed point.
     *
     * @param array<string, list<CapabilityDescriptor>> $surviving
     * @param array<string, CapabilityDescriptor> $local
     *
     * @return array<string, list<CapabilityDescriptor>>
     */
    private function applyDependencies(array $surviving, array $local): array
    {
        do {
            $before = $this->fingerprint($surviving);

            foreach ($surviving as $name => $entries) {
                $kept = array_values(array_filter(
                    $entries,
                    fn (CapabilityDescriptor $entry): bool => $this->extendsSatisfied($entry, $surviving)
                        && $this->requiredCapabilitiesSatisfied($entry, $local[$name], $surviving),
                ));

                if ($kept === []) {
                    unset($surviving[$name]);

                    continue;
                }

                $surviving[$name] = $kept;
            }
        } while ($this->fingerprint($surviving) !== $before);

        return $surviving;
    }

    private function protocolSatisfied(CapabilityDescriptor $descriptor, ?string $protocolVersion): bool
    {
        // With no runtime configuration there is no negotiated protocol version to test
        // against, and inventing one would exclude capabilities on a guess.
        if ($protocolVersion === null || $descriptor->requires === null) {
            return true;
        }

        return $descriptor->requires->allowsProtocol($protocolVersion);
    }

    /**
     * @param array<string, list<CapabilityDescriptor>> $surviving
     */
    private function extendsSatisfied(CapabilityDescriptor $descriptor, array $surviving): bool
    {
        if ($descriptor->extends === null || $descriptor->extends === []) {
            return true;
        }

        // Any, not all: `extends` names the base contracts an entry may build on, and the
        // existing behaviour treats one surviving base as enough. Tightening that to "all"
        // would be a separate decision from fixing the transitivity.
        foreach ($descriptor->extends as $base) {
            if (isset($surviving[$base])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every capability either side requires survived at a version both sides accept.
     *
     * The two `requires` maps are intersected rather than checked one after the other. If we
     * need a capability in one range and the peer needs it in another, the version that has to
     * survive is one in the overlap -- and an empty overlap is a definite incompatibility, not
     * something to discover later from whichever versions happened to be offered.
     *
     * @param array<string, list<CapabilityDescriptor>> $surviving
     */
    private function requiredCapabilitiesSatisfied(
        CapabilityDescriptor $remote,
        CapabilityDescriptor $local,
        array $surviving,
    ): bool {
        foreach ($this->combinedRequirements($remote, $local) as $name => $range) {
            if ($range === null) {
                return false;
            }

            $versions = array_map(
                static fn (CapabilityDescriptor $entry): string => $entry->version,
                $surviving[$name] ?? [],
            );

            if ($range->highestWithin($versions) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, VersionRange|null> null marks a requirement the two sides cannot both satisfy
     */
    private function combinedRequirements(CapabilityDescriptor $remote, CapabilityDescriptor $local): array
    {
        $combined = $remote->requires === null ? [] : $remote->requires->capabilities;

        if ($local->requires === null) {
            return $combined;
        }

        foreach ($local->requires->capabilities as $name => $range) {
            $combined[$name] = isset($combined[$name]) ? $combined[$name]->intersect($range) : $range;
        }

        return $combined;
    }

    /**
     * @param array<string, list<CapabilityDescriptor>> $surviving
     */
    private function fingerprint(array $surviving): string
    {
        $parts = [];
        foreach ($surviving as $name => $entries) {
            $parts[] = $name . ':' . count($entries);
        }

        sort($parts);

        return implode('|', $parts);
    }
}
