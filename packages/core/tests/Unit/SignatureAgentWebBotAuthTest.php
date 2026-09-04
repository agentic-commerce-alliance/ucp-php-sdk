<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\ContentDigestService;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Internal\Security\JwkThumbprint;
use Ucp\Sdk\Internal\Security\Rfc9421RequestSignatureService;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Security\AgentKeyDirectory;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Model\Security\PublicSigningKey;
use Ucp\Sdk\Service\AgentKeyDirectoryFetcherInterface;

/**
 * Web-bot-auth: the signer's keys are not ours, and the request says where to find them.
 *
 * The header is attacker-controlled, so what is asserted here is as much about what the verifier
 * refuses to do as about what it accepts.
 */
final class SignatureAgentWebBotAuthTest extends TestCase
{
    #[Test]
    public function itVerifiesAgainstAKeyFetchedFromTheSignatureAgentUrl(): void
    {
        $manager = new DefaultSigningKeyManager();
        $key = $manager->generate('agent-key', 'EdDSA');
        $public = $manager->toPublicKey($key);
        $thumbprint = (new JwkThumbprint())->compute($public->toJwk());

        $fetcher = $this->fetcherServing('https://agent.example/keys', [$public]);
        $service = new Rfc9421RequestSignatureService(
            new ContentDigestService(),
            agentKeyDirectoryFetcher: $fetcher,
        );

        $request = $this->signedRequest($service, $key, $thumbprint, 'https://agent.example/keys', 'web-bot-auth');

        // The local key set is empty: everything used to verify came from the directory.
        $result = $service->verify($request, []);

        self::assertTrue($result->verified, $result->failureReason ?? '');
        self::assertSame(1, $fetcher->calls, 'the directory is fetched once');
    }

    #[Test]
    public function anUnrecognisedTagIsRefusedRatherThanIgnored(): void
    {
        // A tag names requirements. One this implementation does not know may carry checks it
        // has therefore not applied, and verifying anyway reports a check as passed that was
        // never performed.
        $manager = new DefaultSigningKeyManager();
        $key = $manager->generate('agent-key', 'EdDSA');
        $service = new Rfc9421RequestSignatureService(
            new ContentDigestService(),
            agentKeyDirectoryFetcher: $this->fetcherServing('https://agent.example/keys', []),
        );

        $request = $this->signedRequest($service, $key, $key->kid, 'https://agent.example/keys', 'some-future-tag');
        $result = $service->verify($request, [$manager->toPublicKey($key)]);

        self::assertFalse($result->verified);
        self::assertStringContainsString('Unsupported signature tag', (string) $result->failureReason);
    }

    #[Test]
    public function aDirectoryIsNotFetchedWithoutTheTag(): void
    {
        // Otherwise a `Signature-Agent` header alone would turn every inbound request into an
        // outbound one, which is a request-amplification primitive rather than a feature.
        $manager = new DefaultSigningKeyManager();
        $key = $manager->generate('agent-key');
        $fetcher = $this->fetcherServing('https://agent.example/keys', []);

        $service = new Rfc9421RequestSignatureService(
            new ContentDigestService(),
            agentKeyDirectoryFetcher: $fetcher,
        );

        $request = $this->signedRequest($service, $key, $key->kid, 'https://agent.example/keys', null);
        $service->verify($request, [$manager->toPublicKey($key)]);

        self::assertSame(0, $fetcher->calls);
    }

    #[Test]
    public function anEmptySignatureAgentHeaderIsRefused(): void
    {
        $manager = new DefaultSigningKeyManager();
        $key = $manager->generate('agent-key', 'EdDSA');
        $service = new Rfc9421RequestSignatureService(
            new ContentDigestService(),
            agentKeyDirectoryFetcher: $this->fetcherServing('https://agent.example/keys', []),
        );

        $request = $this->signedRequest($service, $key, $key->kid, '""', 'web-bot-auth');
        $result = $service->verify($request, []);

        self::assertFalse($result->verified);
    }

    #[Test]
    public function aDirectoryKeepsTheKeysItCanUseAndDropsTheRest(): void
    {
        // The same rule a platform profile follows: one key type we cannot verify with must not
        // discard the whole set.
        $manager = new DefaultSigningKeyManager();
        $usable = $manager->toPublicKey($manager->generate('good', 'EdDSA'));

        $directory = AgentKeyDirectory::fromArray('https://agent.example/keys', [
            'keys' => [
                ['kty' => 'RSA', 'kid' => 'unusable', 'n' => 'abc', 'e' => 'AQAB'],
                $usable->toJwk(),
            ],
        ]);

        self::assertCount(1, $directory->keys);
        self::assertSame('good', $directory->keys[0]->kid);
    }

    #[Test]
    public function aDirectoryRoundTripsThroughItsArrayForm(): void
    {
        $manager = new DefaultSigningKeyManager();
        $public = $manager->toPublicKey($manager->generate('agent-key', 'EdDSA'));
        $directory = new AgentKeyDirectory('https://agent.example/keys', [$public]);

        $parsed = AgentKeyDirectory::fromArray($directory->uri, $directory->toArray());

        self::assertCount(1, $parsed->keys);
        self::assertSame($public->x, $parsed->keys[0]->x);
    }

    /**
     * @param list<PublicSigningKey> $keys
     */
    private function fetcherServing(string $uri, array $keys): CountingAgentKeyDirectoryFetcher
    {
        return new CountingAgentKeyDirectoryFetcher($uri, $keys);
    }

    private function signedRequest(
        Rfc9421RequestSignatureService $service,
        ManagedSigningKey $key,
        string $keyid,
        string $signatureAgent,
        ?string $tag,
    ): HttpRequest {
        $request = new HttpRequest('GET', 'https://merchant.example/ucp/v1/orders/o1');

        // Signed *with* the tag: it is a signature parameter, so adding it afterwards would
        // change what `@signature-params` says was covered.
        $headers = $service->sign($request, new ManagedSigningKey(
            $keyid,
            $key->publicKeyPem,
            $key->privateKeyPem,
            $key->algorithm,
            $key->keyType,
            $key->use,
            $key->status,
            $key->curve,
        ), tag: $tag);

        $headers['Signature-Agent'] = str_starts_with($signatureAgent, '"')
            ? $signatureAgent
            : '"' . $signatureAgent . '"';

        return new HttpRequest($request->method, $request->absoluteUri, $headers);
    }
}

/**
 * Counts fetches, because "was the cache used" is a claim about how many times we went out.
 */
final class CountingAgentKeyDirectoryFetcher implements AgentKeyDirectoryFetcherInterface
{
    public int $calls = 0;

    /**
     * @param list<PublicSigningKey> $keys
     */
    public function __construct(
        private readonly string $uri,
        private readonly array $keys,
    ) {
    }

    public function fetch(string $uri): AgentKeyDirectory
    {
        ++$this->calls;

        return new AgentKeyDirectory($this->uri, $this->keys);
    }
}
