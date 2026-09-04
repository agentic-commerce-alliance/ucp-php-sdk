<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;
use Ucp\Sdk\Symfony\Bridge\EmbeddedPageRendererInterface;
use Ucp\Sdk\Symfony\Controller\EmbeddedController;

/**
 * Covers what the embedded transport decides about a browser it does not control.
 *
 * These pages are meant to be framed by an agent, so the controller is the thing
 * standing between "any origin may embed a merchant's checkout" and "only the origins
 * the merchant listed may". The integration kernel tests exercise the happy path with
 * an allowed origin; what they never reach is what happens when there is no origin to
 * allow, when a renderer is registered, or when the transport is switched off -- and
 * those are the branches where being wrong is expensive rather than merely broken.
 */
final class EmbeddedControllerTest extends TestCase
{
    /**
     * A same-origin navigation carries no `Origin` header. That must not be read as
     * "no origin restriction": with nothing to echo, the response has to fall back to
     * framing that is safe by default, so `X-Frame-Options: SAMEORIGIN` is set and no
     * `Access-Control-Allow-Origin` is emitted at all. Emitting an empty or wildcard
     * value here is the mistake this pins.
     */
    #[Test]
    public function aRequestWithNoOriginIsRestrictedToTheMerchantsOwnFrame(): void
    {
        $controller = $this->controller();

        $response = $controller->cart('cart-1', Request::create('https://merchant.example/ucp/embedded/cart/cart-1'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        self::assertFalse($response->headers->has('Access-Control-Allow-Origin'));
        self::assertFalse($response->headers->has('Content-Security-Policy'));
        self::assertSame('Origin', $response->headers->get('Vary'));
    }

    /**
     * An allowed origin gets the mirror image: the origin is echoed, framing is granted
     * to it by CSP, and X-Frame-Options is left off because it cannot express a
     * third-party origin and would contradict the CSP if it were set to SAMEORIGIN.
     */
    #[Test]
    public function anAllowedOriginIsEchoedAndGrantedFramingByCsp(): void
    {
        $controller = $this->controller(allowedAgentDomains: ['https://agent.example']);

        $response = $controller->cart('cart-1', $this->requestFrom('https://agent.example'));

        self::assertSame('https://agent.example', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame("frame-ancestors 'self' https://agent.example", $response->headers->get('Content-Security-Policy'));
        self::assertFalse($response->headers->has('X-Frame-Options'));
    }

    /**
     * Documents a configuration trap rather than an intention. The setting is called
     * `allowed_agent_domains`, but OriginMatcher normalises each entry as a full origin
     * and drops anything without an http/https scheme -- so a merchant who configures
     * `agent.example`, which is what the name asks for, gets an empty allow-list and
     * every agent refused. The entry is discarded silently, which is the part that makes
     * this hard to diagnose from the outside.
     */
    #[Test]
    public function anAllowedDomainWithoutASchemeIsSilentlyIgnored(): void
    {
        $controller = $this->controller(allowedAgentDomains: ['agent.example']);

        $this->expectException(AccessDeniedHttpException::class);

        $controller->cart('cart-1', $this->requestFrom('https://agent.example'));
    }

    #[Test]
    public function anOriginTheMerchantDidNotListIsRefused(): void
    {
        $controller = $this->controller(allowedAgentDomains: ['https://agent.example']);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Embedded origin is not allowed.');

        $controller->cart('cart-1', $this->requestFrom('https://attacker.example'));
    }

    /**
     * A merchant serving only REST has not published these pages, and the honest answer
     * is that the route does not exist -- not 403, which would confirm the resource is
     * there and merely withheld.
     */
    #[Test]
    public function theRoutesAreAbsentWhenTheEmbeddedTransportIsDisabled(): void
    {
        $controller = $this->controller(transports: [Transport::Rest]);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Embedded transport is not enabled.');

        $controller->cart('cart-1', $this->requestFrom('https://agent.example'));
    }

    /**
     * The checkout routes are separate methods from the cart ones and differ only in the
     * type string they pass down, which is also what selects the REST link the page
     * advertises. A copy-paste that left 'cart' in place would produce a checkout page
     * pointing an agent at /ucp/v1/carts, and nothing else would notice.
     */
    #[Test]
    public function theCheckoutPageAdvertisesTheCheckoutRestResourceRatherThanTheCartOne(): void
    {
        $controller = $this->controller();

        $cart = $this->payload($controller->cart('id-1', Request::create('https://merchant.example/ucp/embedded/cart/id-1')));
        $checkout = $this->payload($controller->checkout('id-1', Request::create('https://merchant.example/ucp/embedded/checkout/id-1')));

        self::assertSame('cart', $cart['type']);
        self::assertSame('https://merchant.example/ucp/v1/carts/id-1', $cart['links']['rest']);

        self::assertSame('checkout', $checkout['type']);
        self::assertSame('id-1', $checkout['id']);
        self::assertSame('https://merchant.example/ucp/v1/checkout-sessions/id-1', $checkout['links']['rest']);
    }

    #[Test]
    public function bothPreflightRoutesAnswerWithNoContentAndTheCorsHeaders(): void
    {
        $controller = $this->controller(allowedAgentDomains: ['https://agent.example']);

        foreach ([
            $controller->cartPreflight('cart-1', $this->requestFrom('https://agent.example', 'OPTIONS')),
            $controller->checkoutPreflight('checkout-1', $this->requestFrom('https://agent.example', 'OPTIONS')),
        ] as $response) {
            self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
            self::assertSame('', $response->getContent());
            self::assertSame('GET, OPTIONS', $response->headers->get('Access-Control-Allow-Methods'));
            self::assertSame('https://agent.example', $response->headers->get('Access-Control-Allow-Origin'));
        }
    }

    /**
     * A host that registers a renderer wants its own page served, not the JSON handshake
     * descriptor -- but it must not have to reimplement the origin headers to get it, or
     * a merchant's custom page would be the one page with no framing policy. So the
     * renderer's response is returned, and the headers are still applied to it.
     */
    #[Test]
    public function aRegisteredRenderersResponseIsServedWithTheEmbeddedHeadersApplied(): void
    {
        $renderer = new RecordingEmbeddedPageRenderer(new Response('<html>cart</html>'));
        $controller = $this->controller(allowedAgentDomains: ['https://agent.example'], renderers: [$renderer]);

        $response = $controller->cart('cart-1', $this->requestFrom('https://agent.example'));

        self::assertSame('<html>cart</html>', $response->getContent());
        self::assertSame('https://agent.example', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame(['cart', 'cart-1'], $renderer->calls[0]);
    }

    /**
     * Renderers are consulted in order and the first one willing to answer wins, so a
     * renderer that declines by returning null must not swallow the request. Registering
     * a declining renderer ahead of a rendering one proves the loop continues rather
     * than falling straight through to the JSON descriptor.
     */
    #[Test]
    public function aRendererThatDeclinesDefersToTheNextOne(): void
    {
        $declining = new RecordingEmbeddedPageRenderer(null);
        $rendering = new RecordingEmbeddedPageRenderer(new Response('second'));
        $controller = $this->controller(renderers: [$declining, $rendering]);

        $response = $controller->cart('cart-1', Request::create('https://merchant.example/ucp/embedded/cart/cart-1'));

        self::assertSame('second', $response->getContent());
        self::assertSame([['cart', 'cart-1']], $declining->calls, 'The declining renderer must still have been asked.');
    }

    #[Test]
    public function theJsonDescriptorIsServedWhenEveryRendererDeclines(): void
    {
        $controller = $this->controller(renderers: [new RecordingEmbeddedPageRenderer(null)]);

        $payload = $this->payload($controller->cart('cart-1', Request::create('https://merchant.example/ucp/embedded/cart/cart-1')));

        self::assertSame('embedded', $payload['mode']);
        self::assertTrue($payload['handshake']['postMessage']);
        self::assertSame('ucp.embedded', $payload['handshake']['channel']);
    }

    /**
     * With no `Origin` to target, a postMessage handshake still needs a targetOrigin, and
     * `*` would let any framing page read the message. The merchant's own host is the
     * conservative answer.
     */
    #[Test]
    public function theHandshakeTargetsTheMerchantsOwnHostWhenThereIsNoOrigin(): void
    {
        $controller = $this->controller();

        $payload = $this->payload($controller->cart('cart-1', Request::create('https://merchant.example/ucp/embedded/cart/cart-1')));

        self::assertSame('https://merchant.example', $payload['handshake']['targetOrigin']);
    }

    #[Test]
    public function aPathIdIsUrlEncodedIntoTheRestLink(): void
    {
        $controller = $this->controller();

        $payload = $this->payload($controller->cart('cart id/1', Request::create('https://merchant.example/ucp/embedded/cart/x')));

        self::assertSame('https://merchant.example/ucp/v1/carts/cart%20id%2F1', $payload['links']['rest']);
    }

    private function requestFrom(string $origin, string $method = 'GET'): Request
    {
        return Request::create(
            'https://merchant.example/ucp/embedded/cart/cart-1',
            $method,
            server: ['HTTP_ORIGIN' => $origin],
        );
    }

    /**
     * @param list<Transport>                     $transports
     * @param list<string>                        $allowedAgentDomains
     * @param list<EmbeddedPageRendererInterface> $renderers
     */
    private function controller(
        array $transports = [Transport::Rest, Transport::Embedded],
        array $allowedAgentDomains = [],
        array $renderers = [],
    ): EmbeddedController {
        return new EmbeddedController(
            new FixedRuntimeConfigurationResolver(new RuntimeConfiguration(
                '2026-04-08',
                'https://merchant.example',
                allowedAgentDomains: $allowedAgentDomains,
                transports: $transports,
            )),
            $renderers,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Response $response): array
    {
        self::assertInstanceOf(JsonResponse::class, $response);

        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}

final class FixedRuntimeConfigurationResolver implements RuntimeConfigurationResolverInterface
{
    public function __construct(private readonly RuntimeConfiguration $configuration)
    {
    }

    public function resolve(HttpRequest $request): RuntimeConfiguration
    {
        return $this->configuration;
    }
}

final class RecordingEmbeddedPageRenderer implements EmbeddedPageRendererInterface
{
    /** @var list<array{0: string, 1: string}> */
    public array $calls = [];

    public function __construct(private readonly ?Response $response)
    {
    }

    public function render(string $type, string $id, Request $request): ?Response
    {
        $this->calls[] = [$type, $id];

        return $this->response;
    }
}
