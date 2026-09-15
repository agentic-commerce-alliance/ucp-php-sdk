<?php

declare(strict_types=1);

namespace Ucp\Sdk\Event;

use Ucp\Sdk\Enum\VersionNegotiationOutcome;

/**
 * A platform told this business which UCP version it speaks, and this is what happened.
 *
 * Dispatched once per shopping operation, accepted or refused, from the version check in
 * `ShoppingOperationExecutor`, and once more for the rare request that declares a version in
 * the `UCP-Agent` header and is refused on it before the profile is ever read.
 *
 * This SDK serves exactly one protocol version per release -- see
 * `docs/ucp-version-support-policy.md`. Whether that costs traffic depends on which versions
 * the *agents* pin, and nothing public records that: a UCP platform advertises its version only
 * through the profile its `UCP-Agent` header points at. This event is the measurement. A
 * listener that counts `observedVersion` by `outcome` answers, after one release cycle, whether
 * the single-version decision should be revisited. The profile URI is included so a count can
 * be attributed to a platform; listeners should reduce it to its host before storing anything.
 *
 * Observation only: the event carries no mutators, and the decision it reports has already
 * been taken.
 */
final class VersionNegotiationObservedEvent
{
    public function __construct(
        private readonly string $observedVersion,
        private readonly string $servedVersion,
        private readonly ?string $agentProfileUri,
        private readonly VersionNegotiationOutcome $outcome,
    ) {
    }

    /**
     * The version the platform named: its profile's `ucp.version`, or the `version` parameter
     * of its `UCP-Agent` header when the refusal happened there.
     */
    public function getObservedVersion(): string
    {
        return $this->observedVersion;
    }

    /**
     * The one version this business answers in.
     */
    public function getServedVersion(): string
    {
        return $this->servedVersion;
    }

    public function getAgentProfileUri(): ?string
    {
        return $this->agentProfileUri;
    }

    public function getOutcome(): VersionNegotiationOutcome
    {
        return $this->outcome;
    }
}
