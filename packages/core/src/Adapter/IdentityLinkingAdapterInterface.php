<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Model\Identity\OAuthAuthorizationRequest;
use Ucp\Sdk\Model\Identity\OAuthMetadata;
use Ucp\Sdk\Model\Identity\OAuthTokenRequest;
use Ucp\Sdk\Model\Identity\OAuthTokenResponse;
use Ucp\Sdk\Model\RequestContext;

/**
 * Optional platform-facing contract for identity linking.
 *
 * Projects may implement IdentityLinkingCapabilityInterface directly instead when they
 * do not need a separate adapter layer.
 */
interface IdentityLinkingAdapterInterface
{
    public function getMetadata(RequestContext $context): OAuthMetadata;

    /**
     * @return array<string, mixed>
     */
    public function authorize(OAuthAuthorizationRequest $request, RequestContext $context): array;

    public function issueToken(OAuthTokenRequest $request, RequestContext $context): OAuthTokenResponse;
}
