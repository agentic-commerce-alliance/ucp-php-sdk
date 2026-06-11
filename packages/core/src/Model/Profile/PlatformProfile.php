<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Profile;

use Ucp\Sdk\Model\Security\PublicSigningKey;

final class PlatformProfile
{
    /**
     * @param array<string, list<ServiceEndpoint>> $services
     * @param array<string, list<CapabilityDescriptor>> $capabilities
     * @param array<string, list<PaymentHandlerDescriptor>> $paymentHandlers
     * @param list<PublicSigningKey> $signingKeys
     * @param array<string, string> $supportedVersions
     */
    public function __construct(
        public readonly string $version,
        public readonly array $services,
        public readonly array $capabilities,
        public readonly array $paymentHandlers,
        public readonly array $signingKeys = [],
        public readonly array $supportedVersions = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $ucp = [
            'version' => $this->version,
            'services' => self::jsonObjectMap($this->normalizeServices()),
            'capabilities' => self::jsonObjectMap($this->normalizeCapabilities()),
            'payment_handlers' => self::jsonObjectMap($this->normalizePaymentHandlers()),
        ];

        if ($this->supportedVersions !== []) {
            $ucp['supported_versions'] = $this->supportedVersions;
        }

        return [
            'ucp' => $ucp,
            'signing_keys' => array_map(static fn (PublicSigningKey $key): array => $key->toJwk(), $this->signingKeys),
        ];
    }

    /**
     * @return array<string, list<array<string, string>>>
     */
    private function normalizeServices(): array
    {
        $normalized = [];

        foreach ($this->services as $name => $entries) {
            $normalized[$name] = array_map(static fn (ServiceEndpoint $endpoint): array => $endpoint->toArray(), $entries);
        }

        return $normalized;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function normalizeCapabilities(): array
    {
        $normalized = [];

        foreach ($this->capabilities as $name => $entries) {
            $normalized[$name] = array_map(static fn (CapabilityDescriptor $descriptor): array => $descriptor->toProfileEntry(), $entries);
        }

        return $normalized;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function normalizePaymentHandlers(): array
    {
        $normalized = [];

        foreach ($this->paymentHandlers as $name => $entries) {
            $normalized[$name] = array_map(static fn (PaymentHandlerDescriptor $descriptor): array => $descriptor->toArray(), $entries);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $map
     *
     * @return array<string, mixed>|\stdClass
     */
    private static function jsonObjectMap(array $map): array|\stdClass
    {
        return $map === [] ? new \stdClass() : $map;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $root = is_array($payload['ucp'] ?? null) ? $payload['ucp'] : $payload;
        $services = [];
        $capabilities = [];
        $paymentHandlers = [];

        foreach (self::section($root, $payload, 'services') as $name => $entries) {
            $services[(string) $name] = array_values(array_map(
                static fn (array $entry): ServiceEndpoint => ServiceEndpoint::fromArray($entry),
                is_array($entries) ? array_filter($entries, 'is_array') : [],
            ));
        }

        foreach (self::section($root, $payload, 'capabilities') as $name => $entries) {
            $capabilities[(string) $name] = array_values(array_map(
                static fn (array $entry): CapabilityDescriptor => CapabilityDescriptor::fromProfileEntry((string) $name, $entry),
                is_array($entries) ? array_filter($entries, 'is_array') : [],
            ));
        }

        foreach (self::section($root, $payload, 'payment_handlers') as $name => $entries) {
            $paymentHandlers[(string) $name] = array_values(array_map(
                static fn (array $entry): PaymentHandlerDescriptor => PaymentHandlerDescriptor::fromArray($entry),
                is_array($entries) ? array_filter($entries, 'is_array') : [],
            ));
        }

        $signingKeys = array_values(array_map(
            static fn (array $entry): PublicSigningKey => PublicSigningKey::fromJwk($entry),
            is_array($payload['signing_keys'] ?? null) ? array_filter($payload['signing_keys'], 'is_array') : [],
        ));

        return new self(
            (string) ($root['version'] ?? ''),
            $services,
            $capabilities,
            $paymentHandlers,
            $signingKeys,
            self::stringMap(self::section($root, $payload, 'supported_versions')),
        );
    }

    /**
     * @param array<string, mixed> $root
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function section(array $root, array $payload, string $name): array
    {
        $section = $root[$name] ?? $payload[$name] ?? [];

        return is_array($section) ? $section : [];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, string>
     */
    private static function stringMap(array $payload): array
    {
        $normalized = [];

        foreach ($payload as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }
}
