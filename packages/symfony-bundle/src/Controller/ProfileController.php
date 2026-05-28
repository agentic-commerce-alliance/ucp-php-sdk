<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Service\ProfileBuilderInterface;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

final readonly class ProfileController
{
    public function __construct(
        private ProfileBuilderInterface $profileBuilder,
        private UcpSdkConfiguration $configuration,
    ) {
    }

    #[Route(path: '/.well-known/ucp', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $baseUri = $this->configuration->resolvedBaseUri($request->getSchemeAndHttpHost());
        $profile = $this->profileBuilder->build(new ProfileBuildInput(
            $this->configuration->version,
            $baseUri,
            supportedVersions: $this->configuration->supportedVersions,
        ));

        return new JsonResponse($profile->toArray());
    }
}
