<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\ContentDigestService;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Internal\Security\RepositoryBackedSignatureReplayGuard;
use Ucp\Sdk\Internal\Security\Rfc9421RequestSignatureService;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Repository\SignatureNonceRepositoryInterface;

/**
 * Exercises the signing stack end-to-end with real collaborators (no mocks):
 * key generation, the discovery-profile JSON round-trip a remote verifier would
 * perform, RFC 9421 signing and verification, and replay protection backed by a
 * real nonce repository. The unit suite covers these pieces in isolation; this
 * test wires them together as a single protocol flow.
 */
final class SignedRequestProfileRoundTripTest extends TestCase
{
    #[Test]
    public function itSignsRoundTripsThroughProfileJsonAndVerifiesWithReplayProtection(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('kid-integration');

        // Publish the public key through a discovery profile, then parse it back
        // exactly as a remote verifier would before checking a signature.
        $profile = new PlatformProfile('2026-04-08', [], [], [], [$manager->toPublicKey($managedKey)]);
        $decoded = json_decode(json_encode($profile->toArray(), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $parsedProfile = PlatformProfile::fromArray($decoded);

        $nonceRepository = new InMemorySignatureNonceRepository();
        $service = new Rfc9421RequestSignatureService(
            new ContentDigestService(),
            new RepositoryBackedSignatureReplayGuard($nonceRepository),
        );

        $request = new HttpRequest('post', 'https://merchant.example/ucp/v1/checkout-sessions', [], [], '{"ok":true}');
        $created = time();
        $signedHeaders = $service->sign($request, $managedKey, $created, $created + 120);
        $signedRequest = new HttpRequest($request->method, $request->absoluteUri, $signedHeaders, $request->query, $request->body);

        $firstResult = $service->verify($signedRequest, $parsedProfile->signingKeys);

        self::assertTrue($firstResult->verified, $firstResult->failureReason ?? '');
        self::assertSame('kid-integration', $firstResult->kid);
        self::assertTrue($firstResult->contentDigestVerified);
        self::assertTrue($firstResult->replayChecked);

        // Replaying the identical signature is rejected by the real replay guard
        // and the nonce store that now remembers the first verification.
        $replayResult = $service->verify($signedRequest, $parsedProfile->signingKeys);

        self::assertFalse($replayResult->verified);
        self::assertSame('Request signature replay detected.', $replayResult->failureReason);
    }
}

/**
 * Minimal in-memory nonce store so the replay guard runs against a real
 * repository implementation rather than a test double.
 */
final class InMemorySignatureNonceRepository implements SignatureNonceRepositoryInterface
{
    /**
     * @var array<string, int>
     */
    private array $nonces = [];

    public function has(string $scope, string $kid, string $signatureHash): bool
    {
        return isset($this->nonces[$this->key($scope, $kid, $signatureHash)]);
    }

    public function save(string $scope, string $kid, string $signatureHash, ?int $createdAt = null): void
    {
        $this->nonces[$this->key($scope, $kid, $signatureHash)] = $createdAt ?? time();
    }

    public function saveIfNew(string $scope, string $kid, string $signatureHash, ?int $createdAt = null): bool
    {
        $key = $this->key($scope, $kid, $signatureHash);
        if (isset($this->nonces[$key])) {
            return false;
        }

        $this->nonces[$key] = $createdAt ?? time();

        return true;
    }

    public function purgeExpired(int $olderThanUnixTimestamp): void
    {
        foreach ($this->nonces as $key => $createdAt) {
            if ($createdAt < $olderThanUnixTimestamp) {
                unset($this->nonces[$key]);
            }
        }
    }

    private function key(string $scope, string $kid, string $signatureHash): string
    {
        return $scope . '|' . $kid . '|' . $signatureHash;
    }
}
