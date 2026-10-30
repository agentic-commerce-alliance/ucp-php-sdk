<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Service\ProfileBuilderInterface;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;
use Ucp\Sdk\Symfony\Internal\SymfonyHttpRequest;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

/** @internal */
final class ProfileController
{
    public function __construct(
        private readonly ProfileBuilderInterface $profileBuilder,
        private readonly UcpSdkConfiguration $configuration,
        private readonly RuntimeConfigurationResolverInterface $runtimeConfigurationResolver,
    ) {
    }

    #[Route(path: '/.well-known/ucp', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $baseUri = $this->configuration->resolvedBaseUri($request->getSchemeAndHttpHost());
        $runtimeConfiguration = $this->runtimeConfigurationResolver->resolve(SymfonyHttpRequest::withoutBody($request));
        $baseUri = $runtimeConfiguration->baseUri !== '' ? $runtimeConfiguration->baseUri : $baseUri;

        $profile = $this->profileBuilder->build(new ProfileBuildInput(
            $runtimeConfiguration->version,
            $baseUri,
            $runtimeConfiguration->transports,
            supportedVersions: $runtimeConfiguration->supportedVersions,
            transportEndpoints: $runtimeConfiguration->transportEndpoints,
            tenantIdentifier: $runtimeConfiguration->tenantIdentifier,
            enabledCapabilities: $runtimeConfiguration->enabledCapabilities,
        ));

        $response = new JsonResponse($profile->toArray());

        // Symfony answers `no-cache, private` when nothing sets Cache-Control, and this
        // endpoint set nothing -- so the SDK was telling every platform not to cache the one
        // document they are all expected to. The profile is the same for every caller by
        // construction: it is built from configuration and the published key set, and carries
        // nothing about who asked. `Vary: Origin` is not set for the same reason.
        $response->setPublic();
        $response->setMaxAge($this->configuration->profileCacheMaxAge);
        $response->headers->addCacheControlDirective('must-revalidate');

        return $response;
    }
}
