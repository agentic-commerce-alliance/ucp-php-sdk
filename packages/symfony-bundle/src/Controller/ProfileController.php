<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Service\ProfileBuilderInterface;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;
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
        $runtimeConfiguration = $this->runtimeConfigurationResolver->resolve($this->toHttpRequest($request));
        $baseUri = $runtimeConfiguration->baseUri !== '' ? $runtimeConfiguration->baseUri : $baseUri;

        $profile = $this->profileBuilder->build(new ProfileBuildInput(
            $runtimeConfiguration->version,
            $baseUri,
            $runtimeConfiguration->transports,
            supportedVersions: $runtimeConfiguration->supportedVersions,
            transportEndpoints: $runtimeConfiguration->transportEndpoints,
            tenantIdentifier: $runtimeConfiguration->tenantIdentifier,
        ));

        return new JsonResponse($profile->toArray());
    }

    private function toHttpRequest(Request $request): HttpRequest
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $value) {
            $headers[$name] = implode(', ', array_map(static fn (?string $entry): string => (string) $entry, $value));
        }

        $query = $request->query->all();
        ksort($query);

        return new HttpRequest(
            $request->getMethod(),
            $request->getUri(),
            $headers,
            array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR), $query),
            '',
        );
    }
}
