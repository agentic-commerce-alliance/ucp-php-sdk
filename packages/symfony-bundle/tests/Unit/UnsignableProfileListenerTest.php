<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Ucp\Sdk\Event\ProfileBuiltEvent;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Model\Security\PublicSigningKey;
use Ucp\Sdk\Symfony\EventListener\UnsignableProfileListener;

/**
 * Pins the one thing that used to be silent about a merchant that cannot sign.
 *
 * With no key provisioned and `auto_generate` off, the profile publishes an empty `keys`
 * list and discovery succeeds -- so the deployment looks healthy while no platform can
 * verify anything it sends, and the first visible symptom is a webhook dispatch failing
 * in a way that reads as the business never announcing the order.
 */
final class UnsignableProfileListenerTest extends TestCase
{
    #[Test]
    public function itWarnsWhenThePublishedProfileHasNoSigningKeys(): void
    {
        $logger = new CollectingLogger();
        $listener = new UnsignableProfileListener($logger);

        $listener->onProfileBuilt($this->event($this->profile([])));

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertStringContainsString('no signing keys', $logger->records[0]['message']);
    }

    /**
     * The message has to name both ways out, because they are different decisions: an
     * operator who wants to choose the key runs the command, and one who does not want to
     * think about it enables auto-generation.
     */
    #[Test]
    public function theWarningNamesBothRemediesAndTheDeploymentItAppliesTo(): void
    {
        $logger = new CollectingLogger();

        (new UnsignableProfileListener($logger))->onProfileBuilt($this->event($this->profile([]), 'tenant-a'));

        self::assertStringContainsString('ucp:signing-keys:generate', $logger->records[0]['message']);
        self::assertStringContainsString('auto_generate', $logger->records[0]['message']);
        self::assertSame('https://merchant.example', $logger->records[0]['context']['base_uri']);
        self::assertSame('tenant-a', $logger->records[0]['context']['tenant']);
    }

    #[Test]
    public function itStaysQuietWhenTheProfilePublishesAKey(): void
    {
        $logger = new CollectingLogger();
        $key = new PublicSigningKey(
            'kid-1',
            curve: 'P-256',
            publicKeyPem: "-----BEGIN PUBLIC KEY-----\nx\n-----END PUBLIC KEY-----\n",
        );

        (new UnsignableProfileListener($logger))->onProfileBuilt($this->event($this->profile([$key])));

        self::assertSame([], $logger->records);
    }

    /**
     * Discovery is hit on every negotiation, so warning per build would bury the one line
     * that matters under thousands of copies of itself.
     */
    #[Test]
    public function itWarnsOncePerProcessRatherThanOncePerRequest(): void
    {
        $logger = new CollectingLogger();
        $listener = new UnsignableProfileListener($logger);

        $listener->onProfileBuilt($this->event($this->profile([])));
        $listener->onProfileBuilt($this->event($this->profile([])));
        $listener->onProfileBuilt($this->event($this->profile([])));

        self::assertCount(1, $logger->records);
    }

    /**
     * The logger is optional in this bundle -- it is wired with NULL_ON_INVALID_REFERENCE,
     * matching ExceptionListener -- so an application without one must not fail on the
     * path that only wanted to complain.
     */
    #[Test]
    public function itDoesNothingHarmfulWithoutALogger(): void
    {
        $listener = new UnsignableProfileListener();

        $listener->onProfileBuilt($this->event($this->profile([])));

        $this->expectNotToPerformAssertions();
    }

    /**
     * @param list<PublicSigningKey> $keys
     */
    private function profile(array $keys): PlatformProfile
    {
        return new PlatformProfile('2026-08-25', [], [], [], $keys);
    }

    private function event(PlatformProfile $profile, ?string $tenant = null): ProfileBuiltEvent
    {
        return new ProfileBuiltEvent(
            $profile,
            new ProfileBuildInput('2026-08-25', 'https://merchant.example', tenantIdentifier: $tenant),
        );
    }
}

final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}
