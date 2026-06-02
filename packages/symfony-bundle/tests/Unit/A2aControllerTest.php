<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Enum\SignaturePolicy;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\CapabilityRegistryInterface;
use Ucp\Sdk\Service\ProfileBuilderInterface;
use Ucp\Sdk\Service\ProtocolValidatorInterface;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;
use Ucp\Sdk\Symfony\Controller\A2aController;
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
            supportedVersions: ['2026-04-08' => 'https://merchant.example/.well-known/ucp'],
            transportEndpoints: ['a2a' => 'https://merchant.example/custom-a2a'],
            tenantIdentifier: 'tenant-1',
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

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(7, $payload['id']);
        self::assertSame(-32601, $payload['error']['code']);
        self::assertSame('A2A method "unknown.method" is not supported.', $payload['error']['message']);
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

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('missing-id', $payload['id']);
        self::assertSame('Cart capability is not registered.', $payload['error']['message']);

        $response = $controller->invoke($this->jsonRpcRequest([
            'jsonrpc' => '2.0',
            'id' => 'missing-discount',
            'method' => 'discount.apply',
            'params' => ['cart_id' => 'cart-1'],
        ]));
        $payload = $this->decode($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('A2A discount.apply requires cart_id and code parameters.', $payload['error']['message']);

        $response = $controller->invoke($this->jsonRpcRequest([
            'jsonrpc' => '2.0',
            'id' => 'missing-capability',
            'method' => 'catalog.search',
            'params' => ['query' => 'tent'],
        ]));
        $payload = $this->decode($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Catalog capability is not registered.', $payload['error']['message']);
    }

    private function controller(
        ?RuntimeConfigurationResolverInterface $resolver = null,
        ?ProfileBuilderInterface $profileBuilder = null,
    ): A2aController {
        return new A2aController(
            new A2aCapabilityRegistryFake(),
            new A2aProtocolValidatorFake(),
            new HttpPayloadMapper(),
            $profileBuilder ?? new A2aProfileBuilderFake(),
            $resolver ?? new A2aRuntimeConfigurationResolverFake(new RuntimeConfiguration(
                '2026-04-08',
                'https://merchant.example',
                transports: [Transport::Rest, Transport::A2a],
            )),
            $this->configuration(),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRpcRequest(array $payload): Request
    {
        $request = Request::create('/ucp/a2a', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
        $request->attributes->set('ucp_request_context', new RequestContext('merchant.example'));

        return $request;
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
    public function all(): array
    {
        return [];
    }

    public function find(string $name): ?CapabilityInterface
    {
        return null;
    }

    public function firstImplementing(string $interface): ?CapabilityInterface
    {
        return null;
    }
}

final class A2aProtocolValidatorFake implements ProtocolValidatorInterface
{
    public function validateRequest(string $operation, array $payload, RequestContext $context): void
    {
    }

    public function validateResponse(string $operation, array $payload, RequestContext $context): void
    {
    }
}
