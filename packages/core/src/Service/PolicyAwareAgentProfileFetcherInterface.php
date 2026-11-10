<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Enum\PlatformPolicy;
use Ucp\Sdk\Model\Profile\PlatformProfile;

/**
 * Opt-in extension of {@see RequestScopedAgentProfileFetcherInterface} for callers whose
 * runtime configuration carries a {@see PlatformPolicy} per request. The fetcher then applies
 * the same policy the request-context factory applied, so a resolver that sets the policy per
 * tenant gets one answer from both gates. Consumers may type-check for this interface and
 * fall back to {@see RequestScopedAgentProfileFetcherInterface::fetchForAllowedHosts()}.
 */
interface PolicyAwareAgentProfileFetcherInterface extends RequestScopedAgentProfileFetcherInterface
{
    /**
     * @param list<string> $allowedProfileHosts host allowlist for this request; under
     *        `PlatformPolicy::All` it names the pre-approved platforms that skip discovery
     */
    public function fetchForPolicy(string $uri, array $allowedProfileHosts, PlatformPolicy $platformPolicy): PlatformProfile;
}
