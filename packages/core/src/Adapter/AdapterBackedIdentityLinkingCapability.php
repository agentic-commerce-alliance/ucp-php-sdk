<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Contract\IdentityLinkingCapabilityInterface;
use Ucp\Sdk\Model\Identity\OAuthAuthorizationRequest;
use Ucp\Sdk\Model\Identity\OAuthMetadata;
use Ucp\Sdk\Model\Identity\OAuthTokenRequest;
use Ucp\Sdk\Model\Identity\OAuthTokenResponse;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

/**
 * Optional convenience wrapper for host applications that prefer small platform adapters
 * and a separate capability descriptor. Projects may implement
 * IdentityLinkingCapabilityInterface directly instead.
 */
final class AdapterBackedIdentityLinkingCapability implements IdentityLinkingCapabilityInterface
{
    public function __construct(
        private readonly CapabilityDescriptor $descriptor,
        private readonly IdentityLinkingAdapterInterface $adapter,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return $this->descriptor;
    }

    public function getMetadata(RequestContext $context): OAuthMetadata
    {
        return $this->adapter->getMetadata($context);
    }

    public function authorize(OAuthAuthorizationRequest $request, RequestContext $context): array
    {
        return $this->adapter->authorize($request, $context);
    }

    public function issueToken(OAuthTokenRequest $request, RequestContext $context): OAuthTokenResponse
    {
        return $this->adapter->issueToken($request, $context);
    }
}
