<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\EventListener;

use Psr\Log\LoggerInterface;
use Ucp\Sdk\Event\ProfileBuiltEvent;

/**
 * Says out loud that a merchant is publishing a profile it cannot sign anything with.
 *
 * `RepositoryProfileSigningKeyProvider` returns an empty key set when no key has been
 * provisioned and `signing_keys.auto_generate` is off, and nothing downstream treats that
 * as unusual: discovery succeeds, `keys` is published as an empty list, and a platform
 * reading the profile has no way to verify anything this merchant signs. The first thing
 * that actually fails is a webhook dispatch, which reports itself as the business never
 * announcing the order -- a long way from the cause.
 *
 * The check is here rather than at container build because at build time the answer is
 * not knowable: a key provisioned by hand with `ucp:signing-keys:generate` lives in
 * storage, so `auto_generate: false` is a perfectly correct configuration and warning
 * about it would be wrong about as often as it was right. A warning that cries wolf
 * teaches operators to filter it out. Here the fact is settled -- the key set was just
 * assembled -- and discovery is the first call any platform makes, so this lands within
 * seconds of a deployment that got it wrong.
 *
 * @internal
 */
final class UnsignableProfileListener
{
    private bool $reported = false;

    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    public function onProfileBuilt(ProfileBuiltEvent $event): void
    {
        if ($this->reported || $event->getProfile()->signingKeys !== []) {
            return;
        }

        // Once per process. The condition is a deployment-level misconfiguration, not a
        // per-request event, and repeating it on every discovery hit would bury it.
        $this->reported = true;

        $this->logger?->warning(
            'UCP profile published with no signing keys, so this business cannot sign webhooks and '
            . 'platforms cannot verify anything it sends. Provision a key with "ucp:signing-keys:generate" '
            . 'or set "ucp_sdk.signing_keys.auto_generate: true" to have one created on first use.',
            ['base_uri' => $event->getInput()->baseUri, 'tenant' => $event->getInput()->tenantIdentifier],
        );
    }
}
