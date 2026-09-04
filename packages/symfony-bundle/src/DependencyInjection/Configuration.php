<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
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
                ->scalarNode('version')->defaultValue('2026-04-08')->end()
                ->scalarNode('base_uri')->defaultNull()->end()
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
                ->booleanNode('idempotency_required')->defaultFalse()->end()
                ->integerNode('idempotency_ttl')->defaultValue(86400)->min(1)->end()
                ->integerNode('max_request_body_bytes')->defaultValue(262144)->min(1)->end()
                ->integerNode('platform_profile_cache_ttl')->defaultValue(600)->min(1)->end()
                ->integerNode('negotiation_session_ttl')->defaultValue(604800)->min(1)->end()
                ->integerNode('signature_max_lifetime_seconds')->defaultValue(300)->min(1)->end()
                ->arrayNode('oauth')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('authorization_code_ttl')->defaultValue(600)->min(1)->end()
                    ->end()
                ->end()
                ->arrayNode('supported_versions')
                    ->scalarPrototype()->end()
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
