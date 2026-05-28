<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Identity\OAuthAuthorizationRequest;
use Ucp\Sdk\Model\Identity\OAuthMetadata;
use Ucp\Sdk\Model\Identity\OAuthTokenRequest;
use Ucp\Sdk\Model\Identity\OAuthTokenResponse;
use Ucp\Sdk\Model\RequestContext;

/**
 * OAuth-based identity linking operations.
 *
 * Reference: https://ucp.dev/specification/identity-linking/
 */
interface IdentityLinkingCapabilityInterface extends CapabilityInterface
{
    public function getMetadata(RequestContext $context): OAuthMetadata;

    /**
     * @return array<string, mixed>
     */
    public function authorize(OAuthAuthorizationRequest $request, RequestContext $context): array;

    public function issueToken(OAuthTokenRequest $request, RequestContext $context): OAuthTokenResponse;
}
