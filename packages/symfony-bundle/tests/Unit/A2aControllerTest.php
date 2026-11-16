<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Enum\SignaturePolicy;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Negotiation\NegotiatedCapabilities;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\CapabilityRegistryInterface;
use Ucp\Sdk\Service\HttpRequestContextFactoryInterface;
use Ucp\Sdk\Service\ProfileBuilderInterface;
use Ucp\Sdk\Service\ProtocolValidatorInterface;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;
use Ucp\Sdk\Symfony\Controller\A2aController;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

final class A2aControllerTest extends TestCase
{
    #[Test]
    public function itBuildsAgentCardFromRuntimeConfiguration(): void
    {
        $resolver = new A2aRuntimeConfigurationResolverFake(new RuntimeConfiguration(
            '2026-04-08',
            '',
            SignaturePolicy::Log,
            transports: [Transport::Rest, Transport::A2a],
            supportedVersions: ['2025-10-01' => 'https://merchant.example/.well-known/ucp/2025-10-01'],
            transportEndpoints: ['a2a' => 'https://merchant.example/custom-a2a'],
            tenantIdentifier: 'tenant-1',
            enabledCapabilities: ['dev.ucp.shopping.catalog'],
        ));
        $profileBuilder = new A2aProfileBuilderFake();
        $controller = $this->controller($resolver, $profileBuilder);

        $response = $controller->agentCard(Request::create('/.well-known/agent-card.json?z%5Bnested%5D=1&a=1'));
        $payload = $this->decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://merchant.example/custom-a2a', $payload['url']);
        self::assertSame('2026-04-08', $payload['version']);
        self::assertSame('dev.ucp.shopping.catalog', $payload['skills'][0]['id']);
        self::assertSame(['rest', 'a2a'], $payload['metadata']['transports']);
        self::assertNotNull($profileBuilder->lastInput);
        self::assertSame('https://merchant.example', $profileBuilder->lastInput->baseUri);
        self::assertSame('tenant-1', $profileBuilder->lastInput->tenantIdentifier);
        self::assertSame(['dev.ucp.shopping.catalog'], $profileBuilder->lastInput->enabledCapabilities);
        self::assertNotNull($resolver->lastRequest);
        self::assertSame(['a' => '1', 'z' => '{"nested":"1"}'], $resolver->lastRequest->query);
    }

    #[Test]
    public function itRejectsAgentCardWhenA2aTransportIsDisabled(): void
    {
        $controller = $this->controller(new A2aRuntimeConfigurationResolverFake(new RuntimeConfiguration(
            '2026-04-08',
            'https://merchant.example',
            transports: [Transport::Rest],
        )));

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('A2A transport is not enabled.');

        $controller->agentCard(Request::create('/.well-known/agent-card.json'));
    }

    #[Test]
    public function itReturnsJsonRpcParseErrors(): void
    {
        $controller = $this->controller();
        $request = Request::create('/ucp/a2a', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{');
        $request->attributes->set('ucp_request_context', new RequestContext('merchant.example'));

        $response = $controller->invoke($request);
        $payload = $this->decode($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32700, $payload['error']['code']);
        self::assertSame('Parse error.', $payload['error']['message']);
    }

    #[Test]
    public function itReturnsJsonRpcValidationErrorsForInvalidParams(): void
    {
        $controller = $this->controller();
        $request = $this->jsonRpcRequest([
            'jsonrpc' => '2.0',
            'id' => ['invalid'],
            'method' => 'cart.get',
            'params' => [],
        ]);

        $response = $controller->invoke($request);
        $payload = $this->decode($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($payload['id']);
        self::assertSame(-32602, $payload['error']['code']);
        self::assertSame('JSON-RPC id must be a string, integer, or null.', $payload['error']['message']);

        $request = $this->jsonRpcRequest([
            'jsonrpc' => '2.0',
            'id' => 'call-1',
            'method' => 'cart.get',
            'params' => ['list-entry'],
        ]);
        $response = $controller->invoke($request);
        $payload = $this->decode($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('call-1', $payload['id']);
        self::assertSame('JSON-RPC params must be an object.', $payload['error']['message']);

        $request = $this->jsonRpcRequest([
            'jsonrpc' => '1.0',
            'id' => 'bad-version',
            'method' => 'cart.get',
            'params' => [],
        ]);
        $response = $controller->invoke($request);
        $payload = $this->decode($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('bad-version', $payload['id']);
        self::assertSame('JSON-RPC version must be "2.0".', $payload['error']['message']);

        $request = $this->jsonRpcRequest([
            'jsonrpc' => '2.0',
            'id' => 'missing-method',
            'params' => [],
        ]);
        $response = $controller->invoke($request);
        $payload = $this->decode($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('missing-method', $payload['id']);
        self::assertSame('JSON-RPC method must be a non-empty string.', $payload['error']['message']);
    }

    #[Test]
    public function itReturnsJsonRpcUnsupportedMethodErrors(): void
    {
        $controller = $this->controller();

        $response = $controller->invoke($this->jsonRpcRequest([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'unknown.method',
            'params' => [],
        ]));
        $payload = $this->decode($response);

        // 200, not 404. A JSON-RPC client reads the envelope; an HTTP 404 says the endpoint
        // is missing, which is a different problem with a different remedy, and it discards
        // the error object that names the real one.
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(7, $payload['id']);
        self::assertSame(-32601, $payload['error']['code']);
        self::assertSame('A2A method "unknown.method" is not supported.', $payload['error']['message']);
    }

    #[Test]
    public function itKeepsA2aUpdateIdsInTheValidatedPayload(): void
    {
        $validator = new A2aProtocolValidatorFake();
        $controller = $this->controller(protocolValidator: $validator);

        $response = $controller->invoke($this->jsonRpcRequest([
            'jsonrpc' => '2.0',
            'id' => 'call-1',
            'method' => 'cart.update',
            'params' => [
                'id' => 'cart-1',
                'line_items' => [],
            ],
        ]));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['id' => 'cart-1', 'line_items' => []], $validator->requests['cart.update']);
    }

    #[Test]
    public function itReturnsJsonRpcErrorsForMissingOperationInputsAndCapabilities(): void
    {
        $controller = $this->controller();

        $response = $controller->invoke($this->jsonRpcRequest([
            'jsonrpc' => '2.0',
            'id' => 'missing-id',
            'method' => 'cart.get',
            'params' => [],
        ]));
        $payload = $this->decode($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('missing-id', $payload['id']);
        self::assertSame('cart.get requires a non-empty string id.', $payload['error']['message']);

        $response = $controller->invoke($this->jsonRpcRequest([
            'jsonrpc' => '2.0',
            'id' => 'missing-discount',
            'method' => 'discount.apply',
            'params' => ['cart_id' => 'cart-1'],
        ]));
        $payload = $this->decode($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('discount.apply requires cart_id and code parameters.', $payload['error']['message']);

        $response = $controller->invoke($this->jsonRpcRequest([
            'jsonrpc' => '2.0',
            'id' => 'missing-capability',
            'method' => 'catalog.search',
            'params' => ['query' => 'tent'],
        ]));
        $payload = $this->decode($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Catalog capability is not registered.', $payload['error']['message']);

        $negotiatedController = $this->controller(requestContextFactory: new A2aRequestContextFactoryFake(new RequestContext(
            'merchant.example',
            platformProfile: new PlatformProfile('2026-04-08', [], [], []),
            runtimeConfiguration: new RuntimeConfiguration('2026-04-08', 'https://merchant.example'),
            negotiation: new NegotiatedCapabilities(),
        )));
        $response = $negotiatedController->invoke($this->jsonRpcRequest([
            'jsonrpc' => '2.0',
            'id' => 'not-negotiated',
            'method' => 'catalog.search',
            'params' => ['query' => 'tent'],
        ]));
        $payload = $this->decode($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('not-negotiated', $payload['id']);
        self::assertSame(-32602, $payload['error']['code']);
        self::assertSame('Requested operation is not included in the negotiated capability intersection.', $payload['error']['message']);
    }

    /**
     * The regression for the strict-policy bypass: the controller used to build a bare context
     * itself, so an unsigned call reached the operation. The shared factory refuses it now, and
     * the refusal propagates to ExceptionListener before any capability or validator is touched.
     *
     * @param array<string, mixed> $params
     */
    #[Test]
    #[DataProvider('shoppingOperations')]
    public function itRefusesAnUnauthenticatedCallBeforeTheOperationRuns(string $method, array $params): void
    {
        $registry = new A2aCapabilityRegistryFake();
        $validator = new A2aProtocolValidatorFake();
        $factory = new A2aRequestContextFactoryFake(failure: new SignatureException('Missing signature headers.'));
        $controller = $this->controller(protocolValidator: $validator, requestContextFactory: $factory, capabilityRegistry: $registry);

        try {
            $controller->invoke($this->jsonRpcRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]));
            self::fail('An unauthenticated A2A call must not produce a response.');
        } catch (SignatureException $exception) {
            self::assertSame('Missing signature headers.', $exception->getMessage());
        }

        self::assertCount(1, $factory->requests);
        self::assertSame(0, $registry->lookups);
        self::assertSame([], $validator->requests);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function shoppingOperations(): iterable
    {
        yield 'catalog search' => ['catalog.search', ['query' => 'tent']];
        yield 'cart create' => ['cart.create', ['line_items' => [['item' => ['id' => 'tent-4p'], 'quantity' => 1]]]];
        yield 'checkout complete' => ['checkout.complete', ['id' => 'chk_1', 'payment' => ['instruments' => []]]];
    }

    /**
     * A missing UCP-Agent is a 422 on REST. Inside the JSON-RPC try block it would have become a
     * -32602 with 400, so the factory's exceptions must leave the controller untouched.
     */
    #[Test]
    public function itLeavesFactoryValidationFailuresToTheHttpErrorContract(): void
    {
        $controller = $this->controller(requestContextFactory: new A2aRequestContextFactoryFake(failure: new ValidationException(
            'UCP-Agent header with a profile URI is required for UCP runtime requests.',
            ['$.headers.ucp-agent is required'],
        )));

        $this->expectException(ValidationException::class);

        $controller->invoke($this->jsonRpcRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'catalog.search', 'params' => []]));
    }

    #[Test]
    public function itAuthenticatesBeforeParsingTheBody(): void
    {
        $factory = new A2aRequestContextFactoryFake(failure: new SignatureException('Missing signature headers.'));
        $controller = $this->controller(requestContextFactory: $factory);

        $this->expectException(SignatureException::class);

        try {
            $controller->invoke(Request::create('/ucp/a2a', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{'));
        } finally {
            self::assertCount(1, $factory->requests);
        }
    }

    #[Test]
    public function itHandsTheFactoryTheRawBodyAndTheTargetUri(): void
    {
        $factory = new A2aRequestContextFactoryFake();
        $controller = $this->controller(requestContextFactory: $factory);
        $body = '{"jsonrpc":"2.0", "id":1,"method":"unknown.method","params":{}}';
        $request = Request::create('https://merchant.example/ucp/a2a?b=2&a=1', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_UCP_AGENT' => 'platform; profile="https://platform.example/profile"',
        ], $body);

        $controller->invoke($request);

        self::assertCount(1, $factory->requests);
        $httpRequest = $factory->requests[0];
        self::assertSame('POST', $httpRequest->method);
        self::assertSame('https://merchant.example/ucp/a2a?b=2&a=1', $httpRequest->absoluteUri);
        self::assertSame($body, $httpRequest->body);
        self::assertSame('platform; profile="https://platform.example/profile"', $httpRequest->headers['ucp-agent']);
    }

    #[Test]
    public function itRunsTheOperationWithTheFactoryContextAndIgnoresAPresetAttribute(): void
    {
        $authenticated = new RequestContext('merchant.example', platformProfileUri: 'https://platform.example/profile', signatureVerified: true);
        $validator = new A2aProtocolValidatorFake();
        $controller = $this->controller(protocolValidator: $validator, requestContextFactory: new A2aRequestContextFactoryFake($authenticated));
        $request = $this->jsonRpcRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'cart.update', 'params' => ['id' => 'cart-1', 'line_items' => []]]);
        $request->attributes->set('ucp_request_context', new RequestContext('attacker.example'));

        $controller->invoke($request);

        self::assertSame($authenticated, $request->attributes->get('ucp_request_context'));
        self::assertSame($authenticated, $validator->contexts['cart.update']);
    }

    #[Test]
    public function itAnswersNotFoundForADisabledTransportWithoutAuthenticating(): void
    {
        $factory = new A2aRequestContextFactoryFake(failure: new SignatureException('Missing signature headers.'));
        $controller = $this->controller(
            new A2aRuntimeConfigurationResolverFake(new RuntimeConfiguration('2026-04-08', 'https://merchant.example', transports: [Transport::Rest])),
            requestContextFactory: $factory,
        );

        try {
            $controller->invoke($this->jsonRpcRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'catalog.search', 'params' => []]));
            self::fail('A disabled A2A transport must answer 404.');
        } catch (NotFoundHttpException $exception) {
            self::assertSame('A2A transport is not enabled.', $exception->getMessage());
        }

        self::assertSame([], $factory->requests);
    }

    private function controller(
        ?RuntimeConfigurationResolverInterface $resolver = null,
        ?ProfileBuilderInterface $profileBuilder = null,
        ?ProtocolValidatorInterface $protocolValidator = null,
        ?HttpRequestContextFactoryInterface $requestContextFactory = null,
        ?CapabilityRegistryInterface $capabilityRegistry = null,
    ): A2aController {
        return new A2aController(
            new HttpPayloadMapper(),
            $profileBuilder ?? new A2aProfileBuilderFake(),
            $resolver ?? new A2aRuntimeConfigurationResolverFake(new RuntimeConfiguration(
                '2026-04-08',
                'https://merchant.example',
                transports: [Transport::Rest, Transport::A2a],
            )),
            $this->configuration(),
            new ShoppingOperationExecutor(
                $capabilityRegistry ?? new A2aCapabilityRegistryFake(),
                $protocolValidator ?? new A2aProtocolValidatorFake(),
                new HttpPayloadMapper(),
                [],
                [],
                [],
                new EventDispatcher(),
            ),
            $requestContextFactory ?? new A2aRequestContextFactoryFake(),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRpcRequest(array $payload): Request
    {
        return Request::create('/ucp/a2a', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function configuration(): UcpSdkConfiguration
    {
        return new UcpSdkConfiguration(
            '2026-04-08',
            'https://merchant.example',
            [],
            'log',
            [],
            false,
            86400,
            262144,
            600,
            604800,
            300,
            600,
            [],
            false,
            'default',
            'ES256',
            'P30D',
            'P30D',
            262144,
            10,
            false,
            'sqlite:///%kernel.project_dir%/var/ucp_sdk.sqlite',
            [Transport::Rest, Transport::A2a],
            [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Symfony\Component\HttpFoundation\Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}

final class A2aRuntimeConfigurationResolverFake implements RuntimeConfigurationResolverInterface
{
    public ?HttpRequest $lastRequest = null;

    public function __construct(private RuntimeConfiguration $configuration)
    {
    }

    public function resolve(HttpRequest $request): RuntimeConfiguration
    {
        $this->lastRequest = $request;

        return $this->configuration;
    }
}

final class A2aProfileBuilderFake implements ProfileBuilderInterface
{
    public ?ProfileBuildInput $lastInput = null;

    public function build(ProfileBuildInput $input): PlatformProfile
    {
        $this->lastInput = $input;

        return new PlatformProfile(
            $input->version,
            [],
            [
                'dev.ucp.shopping.catalog' => [
                    new CapabilityDescriptor('dev.ucp.shopping.catalog', '2026-04-08', 'spec', 'schema'),
                ],
            ],
            [],
            supportedVersions: $input->supportedVersions,
        );
    }
}

final class A2aCapabilityRegistryFake implements CapabilityRegistryInterface
{
    public int $lookups = 0;

    public function all(): array
    {
        ++$this->lookups;

        return [];
    }

    public function find(string $name): ?CapabilityInterface
    {
        ++$this->lookups;

        return null;
    }

    public function firstImplementing(string $interface): ?CapabilityInterface
    {
        ++$this->lookups;

        return null;
    }
}

final class A2aRequestContextFactoryFake implements HttpRequestContextFactoryInterface
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    public function __construct(
        private readonly RequestContext $context = new RequestContext('merchant.example'),
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function create(HttpRequest $request): RequestContext
    {
        $this->requests[] = $request;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->context;
    }
}

final class A2aProtocolValidatorFake implements ProtocolValidatorInterface
{
    /**
     * @var array<string, array<string, mixed>>
     */
    public array $requests = [];

    /**
     * @var array<string, RequestContext>
     */
    public array $contexts = [];

    public function validateRequest(string $operation, array $payload, RequestContext $context): void
    {
        $this->requests[$operation] = $payload;
        $this->contexts[$operation] = $context;
    }

    public function validateResponse(string $operation, array $payload, RequestContext $context): void
    {
    }
}
