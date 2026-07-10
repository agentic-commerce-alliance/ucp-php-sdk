<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\DependencyInjection;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;
use Ucp\Sdk\Adapter\CartAdapterInterface;
use Ucp\Sdk\Adapter\CatalogAdapterInterface;
use Ucp\Sdk\Adapter\CheckoutAdapterInterface;
use Ucp\Sdk\Adapter\DiscountAdapterInterface;
use Ucp\Sdk\Adapter\IdentityLinkingAdapterInterface;
use Ucp\Sdk\Adapter\OrderAdapterInterface;
use Ucp\Sdk\Adapter\PaymentAdapterInterface;
use Ucp\Sdk\Contract\Ap2CheckoutMandateVerifierInterface;
use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Contract\CheckoutRequestValidatorInterface;
use Ucp\Sdk\Contract\CheckoutResponseAugmenterInterface;
use Ucp\Sdk\Contract\OrderWebhookEnricherInterface;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Contract\PaymentMandateVerifierInterface;
use Ucp\Sdk\Contract\ProfileContributorInterface;
use Ucp\Sdk\Contract\ProfileSigningKeyProviderInterface;
use Ucp\Sdk\Enum\SignaturePolicy;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Internal\Configuration\StaticRuntimeConfigurationResolver;
use Ucp\Sdk\Internal\Http\HttpAgentProfileFetcher;
use Ucp\Sdk\Internal\Negotiation\DefaultCapabilityNegotiator;
use Ucp\Sdk\Internal\Registry\CapabilityRegistry;
use Ucp\Sdk\Internal\Registry\PaymentHandlerRegistry;
use Ucp\Sdk\Internal\Security\ContentDigestService;
use Ucp\Sdk\Internal\Security\DefaultCheckoutMerchantAuthorizationSigner;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Internal\Security\DetachedJwsService;
use Ucp\Sdk\Internal\Security\RepositoryBackedSignatureReplayGuard;
use Ucp\Sdk\Internal\Security\Rfc9421RequestSignatureService;
use Ucp\Sdk\Internal\Security\UnsupportedMerchantAuthorizationService;
use Ucp\Sdk\Internal\Service\DefaultHttpRequestContextFactory;
use Ucp\Sdk\Internal\Service\DefaultIdempotencyService;
use Ucp\Sdk\Internal\Service\DefaultOrderWebhookDispatcher;
use Ucp\Sdk\Internal\Service\DefaultProfileBuilder;
use Ucp\Sdk\Internal\Service\DefaultProtocolValidator;
use Ucp\Sdk\Internal\Service\RepositoryProfileSigningKeyProvider;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;
use Ucp\Sdk\Internal\Validation\GeneratedSchemaValidator;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Repository\IdempotencyRepositoryInterface;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Repository\NegotiationSessionRepositoryInterface;
use Ucp\Sdk\Repository\OAuthStateRepositoryInterface;
use Ucp\Sdk\Repository\PlatformProfileCacheRepositoryInterface;
use Ucp\Sdk\Repository\SignatureNonceRepositoryInterface;
use Ucp\Sdk\Service\AgentProfileFetcherInterface;
use Ucp\Sdk\Service\CapabilityNegotiatorInterface;
use Ucp\Sdk\Service\CapabilityRegistryInterface;
use Ucp\Sdk\Service\CheckoutMerchantAuthorizationSignerInterface;
use Ucp\Sdk\Service\DeterministicJsonInterface;
use Ucp\Sdk\Service\EventDispatcherInterface;
use Ucp\Sdk\Service\HttpClientInterface;
use Ucp\Sdk\Service\HttpRequestContextFactoryInterface;
use Ucp\Sdk\Service\IdempotencyServiceInterface;
use Ucp\Sdk\Service\MerchantAuthorizationServiceInterface;
use Ucp\Sdk\Service\OrderWebhookPublisherInterface;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;
use Ucp\Sdk\Service\ProfileBuilderInterface;
use Ucp\Sdk\Service\ProtocolValidatorInterface;
use Ucp\Sdk\Service\RequestSignatureServiceInterface;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;
use Ucp\Sdk\Service\SchemaValidatorInterface;
use Ucp\Sdk\Service\SignatureReplayGuardInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;
use Ucp\Sdk\Symfony\Bridge\DefaultStorage\DefaultPrivateKeyEncryptor;
use Ucp\Sdk\Symfony\Bridge\DefaultStorage\SecretEncryptorInterface;
use Ucp\Sdk\Symfony\Bridge\DefaultStorage\StorageCleanupService;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\ConnectionFactory;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\DoctrineDbalIdempotencyRepository;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\DoctrineDbalNegotiationSessionRepository;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\DoctrineDbalOAuthStateRepository;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\DoctrineDbalPlatformProfileCacheRepository;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\DoctrineDbalSignatureNonceRepository;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\DoctrineDbalSigningKeyRepository;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\StorageSchemaDefinition;
use Ucp\Sdk\Symfony\Bridge\EmbeddedPageRendererInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;
use Ucp\Sdk\Symfony\Bridge\SymfonyEventDispatcher;
use Ucp\Sdk\Symfony\Bridge\SymfonyHttpClient;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;
use Ucp\Sdk\Symfony\Command\DeleteSigningKeyCommand;
use Ucp\Sdk\Symfony\Command\GenerateSigningKeyCommand;
use Ucp\Sdk\Symfony\Command\ListSigningKeysCommand;
use Ucp\Sdk\Symfony\Command\PurgeSignatureNoncesCommand;
use Ucp\Sdk\Symfony\Command\RetireSigningKeyCommand;
use Ucp\Sdk\Symfony\Command\ShowPublicSigningKeysCommand;
use Ucp\Sdk\Symfony\Command\StorageCleanupCommand;
use Ucp\Sdk\Symfony\Controller\A2aController;
use Ucp\Sdk\Symfony\Controller\CartController;
use Ucp\Sdk\Symfony\Controller\CatalogController;
use Ucp\Sdk\Symfony\Controller\CheckoutController;
use Ucp\Sdk\Symfony\Controller\EmbeddedController;
use Ucp\Sdk\Symfony\Controller\OAuthController;
use Ucp\Sdk\Symfony\Controller\OrderController;
use Ucp\Sdk\Symfony\Controller\ProfileController;
use Ucp\Sdk\Symfony\Controller\TokenizationController;
use Ucp\Sdk\Symfony\EventListener\ExceptionListener;
use Ucp\Sdk\Symfony\EventListener\IdempotencyResponseListener;
use Ucp\Sdk\Symfony\EventListener\RequestContextListener;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

final class UcpSdkExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        $coreRoot = dirname((string) (new \ReflectionClass(GeneratedSchemaValidator::class))->getFileName(), 4);

        $container->registerForAutoconfiguration(CapabilityInterface::class)->addTag('ucp_sdk.capability');
        $container->registerForAutoconfiguration(PaymentHandlerInterface::class)->addTag('ucp_sdk.payment_handler');
        $container->registerForAutoconfiguration(ProfileContributorInterface::class)->addTag('ucp_sdk.profile_contributor');
        $container->registerForAutoconfiguration(ProfileSigningKeyProviderInterface::class)->addTag('ucp_sdk.profile_signing_key_provider');
        $container->registerForAutoconfiguration(CheckoutRequestValidatorInterface::class)->addTag('ucp_sdk.checkout_request_validator');
        $container->registerForAutoconfiguration(CheckoutResponseAugmenterInterface::class)->addTag('ucp_sdk.checkout_response_augmenter');
        $container->registerForAutoconfiguration(PaymentMandateVerifierInterface::class)->addTag('ucp_sdk.payment_mandate_verifier');
        $container->registerForAutoconfiguration(Ap2CheckoutMandateVerifierInterface::class)->addTag('ucp_sdk.ap2_checkout_mandate_verifier');
        $container->registerForAutoconfiguration(OrderWebhookEnricherInterface::class)->addTag('ucp_sdk.order_webhook_enricher');
        $container->registerForAutoconfiguration(EmbeddedPageRendererInterface::class)->addTag('ucp_sdk.embedded_renderer');
        $container->registerForAutoconfiguration(CatalogAdapterInterface::class)->addTag('ucp_sdk.adapter.catalog');
        $container->registerForAutoconfiguration(CartAdapterInterface::class)->addTag('ucp_sdk.adapter.cart');
        $container->registerForAutoconfiguration(CheckoutAdapterInterface::class)->addTag('ucp_sdk.adapter.checkout');
        $container->registerForAutoconfiguration(OrderAdapterInterface::class)->addTag('ucp_sdk.adapter.order');
        $container->registerForAutoconfiguration(DiscountAdapterInterface::class)->addTag('ucp_sdk.adapter.discount');
        $container->registerForAutoconfiguration(IdentityLinkingAdapterInterface::class)->addTag('ucp_sdk.adapter.identity_linking');
        $container->registerForAutoconfiguration(PaymentAdapterInterface::class)->addTag('ucp_sdk.adapter.payment');

        $transports = array_map(static fn (mixed $transport): Transport => Transport::from((string) $transport), $config['transports']);

        $container->setDefinition(UcpSdkConfiguration::class, new Definition(UcpSdkConfiguration::class, [
            $config['version'],
            $config['base_uri'],
            $config['allowed_profile_hosts'],
            $config['signature_policy'],
            $config['allowed_agent_domains'],
            $config['idempotency_required'],
            $config['idempotency_ttl'],
            $config['max_request_body_bytes'],
            $config['platform_profile_cache_ttl'],
            $config['negotiation_session_ttl'],
            $config['signature_max_lifetime_seconds'],
            $config['oauth']['authorization_code_ttl'],
            $config['supported_versions'],
            $config['signing_keys']['auto_generate'],
            $config['signing_keys']['default_kid'],
            $config['signing_keys']['algorithm'],
            $config['signing_keys']['retire_after'],
            $config['signing_keys']['retired_key_retention'],
            $config['idempotency']['max_stored_response_bytes'],
            $config['webhooks']['timeout'],
            $config['ap2']['enabled'],
            $config['storage']['dsn'],
            $transports,
            $config['transport_endpoints'],
            $config['webhooks']['max_response_body_bytes'],
            $config['profile_fetching_development_mode'],
            $config['enabled_capabilities'],
        ]));

        $container->setDefinition(RuntimeConfiguration::class, new Definition(RuntimeConfiguration::class, [
            $config['version'],
            (string) ($config['base_uri'] ?? ''),
            SignaturePolicy::from($config['signature_policy']),
            $config['idempotency_required'],
            $config['allowed_profile_hosts'],
            $config['allowed_agent_domains'],
            $config['supported_versions'],
            $transports,
            $config['enabled_capabilities'],
            null,
            $config['transport_endpoints'],
            $config['profile_fetching_development_mode'],
        ]));

        $container->setDefinition(StaticRuntimeConfigurationResolver::class, new Definition(StaticRuntimeConfigurationResolver::class, [
            new Reference(RuntimeConfiguration::class),
        ]));
        $container->setAlias(RuntimeConfigurationResolverInterface::class, new Alias(StaticRuntimeConfigurationResolver::class, true));

        $container->setDefinition('ucp_sdk.http_client', (new Definition(HttpClient::class))
            ->setFactory([HttpClient::class, 'create']));
        $container->setDefinition(SymfonyHttpClient::class, new Definition(SymfonyHttpClient::class, [
            new Reference('ucp_sdk.http_client'),
        ]));
        $container->setAlias(HttpClientInterface::class, new Alias(SymfonyHttpClient::class, true));
        $container->setDefinition(SymfonyEventDispatcher::class, new Definition(SymfonyEventDispatcher::class, [
            new Reference(SymfonyEventDispatcherInterface::class),
        ]));
        $container->setAlias(EventDispatcherInterface::class, new Alias(SymfonyEventDispatcher::class, true));

        $container->setDefinition('ucp_sdk.connection', (new Definition(Connection::class))
            ->setFactory([ConnectionFactory::class, 'create'])
            ->setArguments([$config['storage']['dsn']]));

        $container->setDefinition(StorageSchemaDefinition::class, new Definition(StorageSchemaDefinition::class));
        $container->setDefinition(SchemaBootstrapper::class, (new Definition(SchemaBootstrapper::class, [
            new Reference('ucp_sdk.connection'),
            new Reference(StorageSchemaDefinition::class),
        ]))->setPublic(true));

        $container->setDefinition(DefaultPrivateKeyEncryptor::class, new Definition(DefaultPrivateKeyEncryptor::class, [
            '%kernel.secret%',
        ]));
        $container->setAlias(SecretEncryptorInterface::class, new Alias(DefaultPrivateKeyEncryptor::class, true));

        $container->setDefinition(DoctrineDbalSigningKeyRepository::class, new Definition(DoctrineDbalSigningKeyRepository::class, [
            new Reference('ucp_sdk.connection'),
            new Reference(SecretEncryptorInterface::class),
        ]));
        $container->setDefinition(DoctrineDbalIdempotencyRepository::class, new Definition(DoctrineDbalIdempotencyRepository::class, [
            new Reference('ucp_sdk.connection'),
            new Reference(SecretEncryptorInterface::class),
            $config['idempotency_ttl'],
            $config['idempotency']['max_stored_response_bytes'],
        ]));
        $container->setDefinition(DoctrineDbalOAuthStateRepository::class, new Definition(DoctrineDbalOAuthStateRepository::class, [
            new Reference('ucp_sdk.connection'),
            new Reference(SecretEncryptorInterface::class),
            $config['oauth']['authorization_code_ttl'],
        ]));
        $container->setDefinition(DoctrineDbalPlatformProfileCacheRepository::class, new Definition(DoctrineDbalPlatformProfileCacheRepository::class, [
            new Reference('ucp_sdk.connection'),
            $config['platform_profile_cache_ttl'],
        ]));
        $container->setDefinition(DoctrineDbalNegotiationSessionRepository::class, new Definition(DoctrineDbalNegotiationSessionRepository::class, [
            new Reference('ucp_sdk.connection'),
            $config['negotiation_session_ttl'],
        ]));
        $container->setDefinition(DoctrineDbalSignatureNonceRepository::class, new Definition(DoctrineDbalSignatureNonceRepository::class, [
            new Reference('ucp_sdk.connection'),
        ]));

        $container->setAlias(ManagedSigningKeyRepositoryInterface::class, new Alias(DoctrineDbalSigningKeyRepository::class, true));
        $container->setAlias(IdempotencyRepositoryInterface::class, new Alias(DoctrineDbalIdempotencyRepository::class, true));
        $container->setAlias(OAuthStateRepositoryInterface::class, new Alias(DoctrineDbalOAuthStateRepository::class, true));
        $container->setAlias(PlatformProfileCacheRepositoryInterface::class, new Alias(DoctrineDbalPlatformProfileCacheRepository::class, true));
        $container->setAlias(NegotiationSessionRepositoryInterface::class, new Alias(DoctrineDbalNegotiationSessionRepository::class, true));
        $container->setAlias(SignatureNonceRepositoryInterface::class, new Alias(DoctrineDbalSignatureNonceRepository::class, true));

        $container->setDefinition(StorageCleanupService::class, new Definition(StorageCleanupService::class, [
            new Reference(OAuthStateRepositoryInterface::class),
            new Reference(IdempotencyRepositoryInterface::class),
            new Reference(NegotiationSessionRepositoryInterface::class),
            new Reference(PlatformProfileCacheRepositoryInterface::class),
            new Reference(SignatureNonceRepositoryInterface::class),
            new Reference(ManagedSigningKeyRepositoryInterface::class),
            $config['signature_max_lifetime_seconds'],
            $config['signing_keys']['retired_key_retention'],
        ]));

        $container->setDefinition(UrlSafetyValidator::class, new Definition(UrlSafetyValidator::class, [
            $config['allowed_profile_hosts'],
            null,
            $config['profile_fetching_development_mode'],
        ]));
        $container->setDefinition(ContentDigestService::class, new Definition(ContentDigestService::class));
        $container->setDefinition(DefaultJsonCanonicalization::class, new Definition(DefaultJsonCanonicalization::class));
        $container->setAlias(DeterministicJsonInterface::class, new Alias(DefaultJsonCanonicalization::class, true));
        $container->setDefinition(DefaultSigningKeyManager::class, new Definition(DefaultSigningKeyManager::class));
        $container->setAlias(SigningKeyManagerInterface::class, new Alias(DefaultSigningKeyManager::class, true));
        $container->setDefinition(RepositoryBackedSignatureReplayGuard::class, new Definition(RepositoryBackedSignatureReplayGuard::class, [
            new Reference(SignatureNonceRepositoryInterface::class),
        ]));
        $container->setAlias(SignatureReplayGuardInterface::class, new Alias(RepositoryBackedSignatureReplayGuard::class, true));
        $container->setDefinition(Rfc9421RequestSignatureService::class, new Definition(Rfc9421RequestSignatureService::class, [
            new Reference(ContentDigestService::class),
            new Reference(SignatureReplayGuardInterface::class),
            $config['signature_max_lifetime_seconds'],
        ]));
        $container->setAlias(RequestSignatureServiceInterface::class, new Alias(Rfc9421RequestSignatureService::class, true));
        $container->setDefinition(UnsupportedMerchantAuthorizationService::class, new Definition(UnsupportedMerchantAuthorizationService::class));
        $container->setAlias(MerchantAuthorizationServiceInterface::class, new Alias(UnsupportedMerchantAuthorizationService::class, true));
        $container->setDefinition(DetachedJwsService::class, new Definition(DetachedJwsService::class, [
            new Reference(DeterministicJsonInterface::class),
        ]));
        $container->setDefinition(DefaultCheckoutMerchantAuthorizationSigner::class, new Definition(DefaultCheckoutMerchantAuthorizationSigner::class, [
            new Reference(ManagedSigningKeyRepositoryInterface::class),
            new Reference(DetachedJwsService::class),
        ]));
        $container->setAlias(CheckoutMerchantAuthorizationSignerInterface::class, new Alias(DefaultCheckoutMerchantAuthorizationSigner::class, true));
        $container->setDefinition(HttpAgentProfileFetcher::class, new Definition(HttpAgentProfileFetcher::class, [
            new Reference(HttpClientInterface::class),
            new Reference(PlatformProfileCacheRepositoryInterface::class),
            new Reference(UrlSafetyValidator::class),
        ]));
        $container->setAlias(AgentProfileFetcherInterface::class, new Alias(HttpAgentProfileFetcher::class, true));

        $container->setDefinition(CapabilityRegistry::class, new Definition(CapabilityRegistry::class, [
            new TaggedIteratorArgument('ucp_sdk.capability'),
        ]));
        $container->setDefinition(PaymentHandlerRegistry::class, new Definition(PaymentHandlerRegistry::class, [
            new TaggedIteratorArgument('ucp_sdk.payment_handler'),
        ]));
        $container->setAlias(CapabilityRegistryInterface::class, new Alias(CapabilityRegistry::class, true));
        $container->setAlias(PaymentHandlerRegistryInterface::class, new Alias(PaymentHandlerRegistry::class, true));

        $container->setDefinition(GeneratedSchemaValidator::class, new Definition(GeneratedSchemaValidator::class, [
            $coreRoot . '/resources/schema/generated/2026-04-08',
        ]));
        $container->setAlias(SchemaValidatorInterface::class, new Alias(GeneratedSchemaValidator::class, true));
        $container->setDefinition(DefaultProtocolValidator::class, new Definition(DefaultProtocolValidator::class, [
            new Reference(SchemaValidatorInterface::class),
        ]));
        $container->setAlias(ProtocolValidatorInterface::class, new Alias(DefaultProtocolValidator::class, true));

        $container->setDefinition(DefaultCapabilityNegotiator::class, new Definition(DefaultCapabilityNegotiator::class, [
            new Reference(CapabilityRegistryInterface::class),
            new Reference(PaymentHandlerRegistryInterface::class),
        ]));
        $container->setAlias(CapabilityNegotiatorInterface::class, new Alias(DefaultCapabilityNegotiator::class, true));

        $container->setDefinition(DefaultHttpRequestContextFactory::class, new Definition(DefaultHttpRequestContextFactory::class, [
            new Reference(RuntimeConfigurationResolverInterface::class),
            new Reference(AgentProfileFetcherInterface::class),
            new Reference(RequestSignatureServiceInterface::class),
            new Reference(CapabilityNegotiatorInterface::class),
            new Reference(NegotiationSessionRepositoryInterface::class),
            $config['ap2']['enabled'] ? new Reference(MerchantAuthorizationServiceInterface::class) : null,
        ]));
        $container->setAlias(HttpRequestContextFactoryInterface::class, new Alias(DefaultHttpRequestContextFactory::class, true));

        $container->setDefinition(DefaultIdempotencyService::class, new Definition(DefaultIdempotencyService::class, [
            new Reference(IdempotencyRepositoryInterface::class),
        ]));
        $container->setAlias(IdempotencyServiceInterface::class, new Alias(DefaultIdempotencyService::class, true));

        $container->setDefinition(RepositoryProfileSigningKeyProvider::class, new Definition(RepositoryProfileSigningKeyProvider::class, [
            new Reference(ManagedSigningKeyRepositoryInterface::class),
            new Reference(SigningKeyManagerInterface::class),
            $config['signing_keys']['auto_generate'],
            $config['signing_keys']['default_kid'],
            $config['signing_keys']['algorithm'],
            $config['signing_keys']['retire_after'],
        ]));
        $container->getDefinition(RepositoryProfileSigningKeyProvider::class)->addTag('ucp_sdk.profile_signing_key_provider');

        $container->setDefinition(DefaultProfileBuilder::class, new Definition(DefaultProfileBuilder::class, [
            new Reference(CapabilityRegistryInterface::class),
            new Reference(PaymentHandlerRegistryInterface::class),
            new TaggedIteratorArgument('ucp_sdk.profile_contributor'),
            new TaggedIteratorArgument('ucp_sdk.profile_signing_key_provider'),
            new Reference(EventDispatcherInterface::class),
        ]));
        $container->setAlias(ProfileBuilderInterface::class, new Alias(DefaultProfileBuilder::class, true));

        $container->setDefinition(DefaultOrderWebhookDispatcher::class, new Definition(DefaultOrderWebhookDispatcher::class, [
            new Reference(ManagedSigningKeyRepositoryInterface::class),
            new Reference(RequestSignatureServiceInterface::class),
            new Reference(HttpClientInterface::class),
            new TaggedIteratorArgument('ucp_sdk.order_webhook_enricher'),
            new Reference(EventDispatcherInterface::class),
            $config['webhooks']['timeout'],
            new Reference(UrlSafetyValidator::class),
            $config['webhooks']['max_response_body_bytes'],
        ]));
        $container->setAlias(OrderWebhookPublisherInterface::class, new Alias(DefaultOrderWebhookDispatcher::class, true));

        $container->autowire(HttpPayloadMapper::class);
        $container->autowire(UcpResponseFactory::class)
            ->setArgument('$configuration', new Reference(UcpSdkConfiguration::class));
        $container->autowire(ShoppingOperationExecutor::class)
            ->setArgument('$requestValidators', new TaggedIteratorArgument('ucp_sdk.checkout_request_validator'))
            ->setArgument('$responseAugmenters', new TaggedIteratorArgument('ucp_sdk.checkout_response_augmenter'))
            ->setArgument('$mandateVerifiers', new TaggedIteratorArgument('ucp_sdk.payment_mandate_verifier'))
            ->setArgument('$ap2CheckoutMandateVerifiers', new TaggedIteratorArgument('ucp_sdk.ap2_checkout_mandate_verifier'));

        $container->autowire(ProfileController::class)->addTag('controller.service_arguments');
        $container->autowire(CatalogController::class)->addTag('controller.service_arguments');
        $container->autowire(CartController::class)->addTag('controller.service_arguments');
        $container->autowire(CheckoutController::class)->addTag('controller.service_arguments');
        $container->autowire(TokenizationController::class)->addTag('controller.service_arguments');
        $container->autowire(OrderController::class)->addTag('controller.service_arguments');
        $container->autowire(OAuthController::class)->addTag('controller.service_arguments');
        $container->autowire(A2aController::class)->addTag('controller.service_arguments');
        $container->autowire(EmbeddedController::class)
            ->setArgument('$renderers', new TaggedIteratorArgument('ucp_sdk.embedded_renderer'))
            ->addTag('controller.service_arguments');

        $container->autowire(RequestContextListener::class)
            ->addTag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onKernelRequest']);
        $container->autowire(IdempotencyResponseListener::class)
            ->addTag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'onKernelResponse']);
        $container->autowire(ExceptionListener::class)
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onKernelException']);

        $container->setDefinition(GenerateSigningKeyCommand::class, new Definition(GenerateSigningKeyCommand::class, [
            new Reference(SigningKeyManagerInterface::class),
            new Reference(ManagedSigningKeyRepositoryInterface::class),
            $config['signing_keys']['default_kid'],
            $config['signing_keys']['algorithm'],
            $config['signing_keys']['retire_after'],
        ]))->addTag('console.command');
        $container->autowire(ListSigningKeysCommand::class)->addTag('console.command');
        $container->autowire(ShowPublicSigningKeysCommand::class)->addTag('console.command');
        $container->autowire(RetireSigningKeyCommand::class)->addTag('console.command');
        $container->autowire(DeleteSigningKeyCommand::class)->addTag('console.command');
        $container->autowire(StorageCleanupCommand::class)->addTag('console.command');
        $container->autowire(PurgeSignatureNoncesCommand::class)->addTag('console.command');
    }
}
