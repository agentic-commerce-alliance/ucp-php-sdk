<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Ucp\Sdk\Enum\UcpProtocolVersion;
use Ucp\Sdk\Internal\Security\AgentDomainAllowList;
use Ucp\Sdk\Internal\Service\DefaultOrderWebhookDispatcher;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('ucp_sdk');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                // Setting this at all is discouraged: a release serves exactly one protocol
                // version, so the only value that can ever be right is the default. A
                // deployment that had pinned the previous version and then took an SDK
                // upgrade used to fail here at container build, which surfaced as
                // `assets:install` returning 255 part-way through a Shopware core upgrade --
                // one stale line in one bundle's config taking the whole shop offline, with
                // nothing in the message to say which line. A version this release knows but
                // no longer serves is now corrected to the served one with a deprecation
                // (see UcpSdkExtension::resolveServedVersion); only a version the SDK cannot
                // name at all is still refused, because nothing downstream could act on it.
                ->scalarNode('version')
                    ->defaultValue(UcpProtocolVersion::current()->value)
                    ->info('UCP protocol version to serve. Leave unset: a release serves exactly one, and it is the default.')
                    ->validate()
                        ->ifTrue(static fn (mixed $version): bool => ! is_string($version) || ! UcpProtocolVersion::isKnown($version))
                        ->thenInvalid(sprintf(
                            'Unknown UCP protocol version %%s. This SDK release serves %s and can name %s.',
                            implode(', ', UcpProtocolVersion::supportedVersions()),
                            implode(', ', UcpProtocolVersion::knownVersions()),
                        ))
                    ->end()
                ->end()
                ->scalarNode('base_uri')->defaultNull()->end()
                ->arrayNode('legacy_routes')
                    ->addDefaultsIfNotSet()
                    ->children()
                        // On by default for one minor: the route is non-conformant, so no
                        // conformant peer can depend on it, but adopters shipped against it.
                        ->booleanNode('catalog_product_get')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('allowed_profile_hosts')
                    ->scalarPrototype()->end()
                ->end()
                // Refused at container build rather than skipped at request time. An
                // unusable entry used to be dropped silently, which left an operator with
                // an allow-list that refused every agent and no indication why.
                ->arrayNode('allowed_agent_domains')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $entry): bool => ! is_string($entry) || ! AgentDomainAllowList::isUsableEntry($entry))
                            ->thenInvalid(
                                'Invalid ucp_sdk.allowed_agent_domains entry %s. Write a domain such as '
                                . '"agent.example", which also covers its subdomains, or a full origin such as '
                                . '"https://agent.example" to pin the scheme and port.',
                            )
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('profile_fetching_development_mode')->defaultFalse()->end()
                ->enumNode('signature_policy')->values(['log', 'strict', 'off'])->defaultValue('log')->end()
                ->arrayNode('response_signing')
                    ->info('Sign UCP responses per RFC 9421, bound to the request that produced them.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        // Off by default: a business with no signing key would otherwise start
                        // logging a warning on every response the moment it upgrades, and
                        // response signing is something an operator opts into once its peers
                        // are ready to verify it.
                        ->booleanNode('enabled')->defaultFalse()->end()
                    ->end()
                ->end()
                ->booleanNode('idempotency_required')->defaultFalse()->end()
                ->integerNode('idempotency_ttl')->defaultValue(86400)->min(1)->end()
                ->integerNode('max_request_body_bytes')->defaultValue(262144)->min(1)->end()
                // How long we cache *their* profile at most. The platform's own Cache-Control
                // decides the actual freshness, floored at the spec's 60 seconds and capped here;
                // a profile that sends no directive is cached for exactly this long.
                ->integerNode('platform_profile_cache_ttl')->defaultValue(600)->min(60)->end()
                // How long platforms may cache *our* profile, as opposed to how long we cache
                // theirs. The floor is the spec's: below 60 the document is effectively
                // uncacheable, which is the state this setting exists to leave behind.
                ->integerNode('profile_cache_max_age')->defaultValue(300)->min(60)->end()
                ->integerNode('negotiation_session_ttl')->defaultValue(604800)->min(1)->end()
                ->integerNode('signature_max_lifetime_seconds')->defaultValue(300)->min(1)->end()
                ->arrayNode('oauth')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('authorization_code_ttl')->defaultValue(600)->min(1)->end()
                    ->end()
                ->end()
                // Older protocol versions this *business* still serves, each at its own
                // self-contained profile URI -- `supported_versions` in the profile document.
                // Listing a version here does not make this instance answer it: this SDK
                // release serves exactly `version`, and a request from a platform on any
                // other version is refused with `version_unsupported` regardless of what is
                // listed. The node exists for the operator who keeps an older SDK release
                // deployed at another URL and wants the newest profile to point at it. See
                // docs/ucp-version-support-policy.md.
                ->arrayNode('supported_versions')
                    ->info('Map of older UCP version (YYYY-MM-DD) to the profile URI of the deployment that serves it. Advertised only; this instance does not answer them.')
                    // The keys are wire values. Symfony's default key normalisation rewrote
                    // `2026-04-08` to `2026_04_08`, so the profile advertised a version no
                    // platform could ever match -- silently, since nothing validated the map.
                    ->normalizeKeys(false)
                    ->scalarPrototype()->end()
                    ->validate()
                        ->ifTrue(static function (mixed $versions): bool {
                            if (! is_array($versions)) {
                                return true;
                            }

                            foreach ($versions as $version => $profileUri) {
                                if (! is_string($version) || preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $version) !== 1) {
                                    return true;
                                }

                                if (! is_string($profileUri) || trim($profileUri) === '') {
                                    return true;
                                }
                            }

                            return false;
                        })
                        ->thenInvalid('Invalid ucp_sdk.supported_versions %s. Keys must be UCP version dates (YYYY-MM-DD) and values the non-empty profile URI serving that version.')
                    ->end()
                ->end()
                ->arrayNode('enabled_capabilities')
                    ->defaultValue([])
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('transports')
                    ->defaultValue(['rest'])
                    ->scalarPrototype()
                        ->validate()
                            ->ifNotInArray(['rest', 'mcp', 'a2a', 'embedded'])
                            ->thenInvalid('Unsupported UCP transport "%s". Supported transports are rest, mcp, a2a, and embedded.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('transport_endpoints')
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('signing_keys')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('auto_generate')->defaultFalse()->end()
                        ->scalarNode('default_kid')->defaultValue('default')->end()
                        ->scalarNode('algorithm')->defaultValue('ES256')->end()
                        ->scalarNode('retire_after')->defaultValue('P30D')->end()
                        ->scalarNode('retired_key_retention')->defaultValue('P30D')->end()
                    ->end()
                ->end()
                ->arrayNode('idempotency')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_stored_response_bytes')->defaultValue(262144)->min(1)->end()
                    ->end()
                ->end()
                ->arrayNode('webhooks')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('timeout')->defaultValue(10)->min(1)->end()
                        ->integerNode('max_response_body_bytes')
                            ->info('Maximum webhook response body size stored by the SDK in bytes. Defaults to 256 KiB; larger bodies are discarded.')
                            ->defaultValue(DefaultOrderWebhookDispatcher::DEFAULT_MAX_RESPONSE_BODY_BYTES)
                            ->min(1)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('ap2')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                    ->end()
                ->end()
                ->arrayNode('storage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('dsn')->defaultValue('sqlite:///%kernel.project_dir%/var/ucp_sdk.sqlite')->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $config): bool => in_array('mcp', $config['transports'] ?? [], true) && (($config['transport_endpoints']['mcp'] ?? '') === ''))
                ->thenInvalid('MCP transport requires an explicit "mcp" transport endpoint.')
            ->end();

        return $treeBuilder;
    }
}
