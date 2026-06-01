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
use Ucp\Sdk\Symfony\Bridge\EmbeddedPageRendererInterface;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

final readonly class EmbeddedController
{
    /**
     * @param iterable<EmbeddedPageRendererInterface> $renderers
     */
    public function __construct(
        private UcpSdkConfiguration $configuration,
        private iterable $renderers = [],
    ) {
    }

    #[Route(path: '/ucp/embedded/cart/{cartId}', methods: ['GET'])]
    public function cart(string $cartId, Request $request): Response
    {
        return $this->response('cart', $cartId, $request);
    }

    #[Route(path: '/ucp/embedded/checkout/{checkoutId}', methods: ['GET'])]
    public function checkout(string $checkoutId, Request $request): Response
    {
        return $this->response('checkout', $checkoutId, $request);
    }

    private function response(string $type, string $id, Request $request): Response
    {
        if (! $this->configuration->supportsTransport(Transport::Embedded)) {
            throw new NotFoundHttpException('Embedded transport is not enabled.');
        }

        $origin = $request->headers->get('origin');
        if (is_string($origin) && $origin !== '' && ! $this->configuration->allowsOrigin($origin, $request->getSchemeAndHttpHost())) {
            throw new AccessDeniedHttpException('Embedded origin is not allowed.');
        }

        foreach ($this->renderers as $renderer) {
            $response = $renderer->render($type, $id, $request);
            if ($response instanceof Response) {
                return $response;
            }
        }

        $headers = [
            'Vary' => 'Origin',
        ];

        if (is_string($origin) && $origin !== '') {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Content-Security-Policy'] = "frame-ancestors 'self' " . $origin;
        } else {
            $headers['X-Frame-Options'] = 'SAMEORIGIN';
        }

        return new JsonResponse([
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
        ], Response::HTTP_OK, $headers);
    }
}
