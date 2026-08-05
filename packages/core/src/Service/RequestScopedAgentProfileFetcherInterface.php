<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Model\Profile\PlatformProfile;

/**
 * Opt-in extension of {@see AgentProfileFetcherInterface} for callers that scope
 * the allowed profile hosts per request (e.g. per sales channel / tenant) rather
 * than relying on a single process-wide allowlist. Consumers may type-check for
 * this interface and fall back to {@see AgentProfileFetcherInterface::fetch()}.
 */
interface RequestScopedAgentProfileFetcherInterface extends AgentProfileFetcherInterface
{
    /**
     * @param list<string> $allowedProfileHosts host allowlist for this request; an
     *        empty list falls back to the fetcher's configured default allowlist
     */
    public function fetchForAllowedHosts(string $uri, array $allowedProfileHosts): PlatformProfile;
}
