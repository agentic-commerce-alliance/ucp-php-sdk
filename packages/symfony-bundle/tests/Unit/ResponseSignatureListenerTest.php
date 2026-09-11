<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Ucp\Sdk\Internal\Security\ContentDigestService;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Internal\Security\Rfc9421ResponseSignatureService;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Symfony\EventListener\ResponseSignatureListener;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

final class ResponseSignatureListenerTest extends TestCase
{
    #[Test]
    public function itSignsAUcpResponseWhenEnabled(): void
    {
        $response = $this->handle('/ucp/v1/checkout-sessions', new Response('{"id":"chk_1"}', 201));

        self::assertTrue($response->headers->has('Signature'));
        self::assertTrue($response->headers->has('Signature-Input'));
        self::assertTrue($response->headers->has('Content-Digest'));
        self::assertStringContainsString('"@status"', (string) $response->headers->get('Signature-Input'));
        self::assertStringContainsString('"@target-uri";req', (string) $response->headers->get('Signature-Input'));
    }

    #[Test]
    public function aReplayedResponseIsSignedToo(): void
    {
        // The reason this listener runs at a negative priority. A replay is produced by the
        // idempotency layer, and one that went out unsigned while the original was signed would
        // give the same question two different levels of proof, with nothing telling the caller.
        $replayed = new Response('{"id":"chk_1"}', 201, ['Idempotency-Replay' => '1']);

        $response = $this->handle('/ucp/v1/checkout-sessions', $replayed);

        self::assertTrue($response->headers->has('Signature'), 'a replay carries a signature');
        self::assertSame('1', $response->headers->get('Idempotency-Replay'));
    }

    #[Test]
    public function itLeavesNonUcpResponsesAlone(): void
    {
        $response = $this->handle('/merchant/checkout/chk_1', new Response('<html></html>'));

        self::assertFalse($response->headers->has('Signature'));
    }

    #[Test]
    public function itDoesNothingWhenDisabled(): void
    {
        $response = $this->handle('/ucp/v1/checkout-sessions', new Response('{}'), enabled: false);

        self::assertFalse($response->headers->has('Signature'));
    }

    #[Test]
    public function itAnswersUnsignedRatherThanFailingWhenNoKeyExists(): void
    {
        // The caller asked for a checkout, not for a signature. A business with no key has a
        // deployment problem, and turning that into a 500 makes it the caller's problem.
        $response = $this->handle('/ucp/v1/checkout-sessions', new Response('{}'), keys: []);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->headers->has('Signature'));
    }

    #[Test]
    public function itDoesNotSignTwice(): void
    {
        $already = new Response('{}', 200, ['Signature' => 'sig=:existing:']);

        $response = $this->handle('/ucp/v1/checkout-sessions', $already);

        self::assertSame('sig=:existing:', $response->headers->get('Signature'));
    }

    private function configuration(bool $responseSigningEnabled): UcpSdkConfiguration
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
            'sqlite:///:memory:',
            responseSigningEnabled: $responseSigningEnabled,
        );
    }

    /**
     * @param list<ManagedSigningKey>|null $keys
     */
    private function handle(string $path, Response $response, bool $enabled = true, ?array $keys = null): Response
    {
        $keys ??= [(new DefaultSigningKeyManager())->generate('kid-1')];

        $repository = $this->createMock(ManagedSigningKeyRepositoryInterface::class);
        $repository->method('active')->willReturn($keys);

        $listener = new ResponseSignatureListener(
            $this->configuration($enabled),
            new Rfc9421ResponseSignatureService(new ContentDigestService()),
            $repository,
        );

        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($path, 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        return $event->getResponse();
    }
}
