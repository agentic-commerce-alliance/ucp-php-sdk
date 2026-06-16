<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;
use Ucp\Sdk\Symfony\Bridge\EmbeddedPageRendererInterface;
use Ucp\Sdk\Symfony\Internal\OriginMatcher;

final class EmbeddedController
{
    /**
     * @param iterable<EmbeddedPageRendererInterface> $renderers
     */
    public function __construct(
        private readonly RuntimeConfigurationResolverInterface $runtimeConfigurationResolver,
        private readonly iterable $renderers = [],
    ) {
    }

    #[Route(path: '/ucp/embedded/cart/{cartId}', methods: ['GET'])]
    public function cart(string $cartId, Request $request): Response
    {
        return $this->response('cart', $cartId, $request);
    }

    #[Route(path: '/ucp/embedded/cart/{cartId}', methods: ['OPTIONS'])]
    public function cartPreflight(string $cartId, Request $request): Response
    {
        return $this->preflight($request);
    }

    #[Route(path: '/ucp/embedded/checkout/{checkoutId}', methods: ['GET'])]
    public function checkout(string $checkoutId, Request $request): Response
    {
        return $this->response('checkout', $checkoutId, $request);
    }

    #[Route(path: '/ucp/embedded/checkout/{checkoutId}', methods: ['OPTIONS'])]
    public function checkoutPreflight(string $checkoutId, Request $request): Response
    {
        return $this->preflight($request);
    }

    private function response(string $type, string $id, Request $request): Response
    {
        $origin = $this->allowedOrigin($request);

        foreach ($this->renderers as $renderer) {
            $response = $renderer->render($type, $id, $request);
            if ($response instanceof Response) {
                return $this->withEmbeddedHeaders($response, $request, $origin);
            }
        }

        return $this->withEmbeddedHeaders(new JsonResponse([
            'type' => $type,
            'id' => $id,
            'mode' => 'embedded',
            'handshake' => [
                'postMessage' => true,
                'channel' => 'ucp.embedded',
                'targetOrigin' => $origin ?: $request->getSchemeAndHttpHost(),
            ],
            'links' => [
                'self' => $request->getUri(),
                'rest' => $type === 'cart'
                    ? rtrim($request->getSchemeAndHttpHost(), '/') . '/ucp/v1/carts/' . rawurlencode($id)
                    : rtrim($request->getSchemeAndHttpHost(), '/') . '/ucp/v1/checkout-sessions/' . rawurlencode($id),
            ],
        ]), $request, $origin);
    }

    private function preflight(Request $request): Response
    {
        $origin = $this->allowedOrigin($request);

        return $this->withEmbeddedHeaders(new Response('', Response::HTTP_NO_CONTENT), $request, $origin);
    }

    private function allowedOrigin(Request $request): ?string
    {
        $runtimeConfiguration = $this->runtimeConfigurationResolver->resolve($this->toHttpRequest($request));
        if (! in_array(Transport::Embedded, $runtimeConfiguration->transports, true)) {
            throw new NotFoundHttpException('Embedded transport is not enabled.');
        }

        $origin = $request->headers->get('origin');
        if (! is_string($origin) || $origin === '') {
            return null;
        }

        if (! $this->allowsOrigin($origin, $runtimeConfiguration, $request->getSchemeAndHttpHost())) {
            throw new AccessDeniedHttpException('Embedded origin is not allowed.');
        }

        return $origin;
    }

    private function withEmbeddedHeaders(Response $response, Request $request, ?string $origin): Response
    {
        $response->headers->set('Vary', 'Origin', false);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept');

        if (is_string($origin) && $origin !== '') {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'self' " . $origin);

            return $response;
        }

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        return $response;
    }

    private function allowsOrigin(string $origin, RuntimeConfiguration $runtimeConfiguration, string $fallbackBaseUri): bool
    {
        return OriginMatcher::allows($origin, $runtimeConfiguration->allowedAgentDomains, $runtimeConfiguration->baseUri !== '' ? $runtimeConfiguration->baseUri : $fallbackBaseUri);
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
