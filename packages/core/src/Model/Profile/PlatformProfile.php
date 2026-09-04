<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Profile;

use Ucp\Sdk\Exception\ValidationException;
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
            // `keys` is the canonical field. 2026-08-25 removed `signing_keys` from
            // profile.json and promoted `keys` as a JWK Set (RFC 7517), which is also what
            // makes the profile reusable as a Web Bot Auth key source. The PHP property stays
            // `$signingKeys`: that is this SDK's name for it, not the wire's, and renaming it
            // would break every adopter that constructs or reads a profile for no protocol
            // reason.
            'keys' => array_map(static fn (PublicSigningKey $key): array => $key->toJwk(), $this->signingKeys),
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
        $root = self::root($payload);
        $services = [];
        $capabilities = [];
        $paymentHandlers = [];

        foreach (self::entrySection($root, $payload, 'services') as $name => $entries) {
            $services[$name] = array_map(
                static fn (array $entry): ServiceEndpoint => ServiceEndpoint::fromArray($entry),
                $entries,
            );
        }

        foreach (self::entrySection($root, $payload, 'capabilities') as $name => $entries) {
            $capabilities[$name] = array_map(
                static fn (array $entry): CapabilityDescriptor => CapabilityDescriptor::fromProfileEntry($name, $entry),
                $entries,
            );
        }

        foreach (self::entrySection($root, $payload, 'payment_handlers') as $name => $entries) {
            $paymentHandlers[$name] = array_map(
                static fn (array $entry): PaymentHandlerDescriptor => PaymentHandlerDescriptor::fromArray($entry),
                $entries,
            );
        }

        return new self(
            self::version($root),
            $services,
            $capabilities,
            $paymentHandlers,
            self::signingKeys($payload),
            self::stringMap(self::section($root, $payload, 'supported_versions')),
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function root(array $payload): array
    {
        if (array_key_exists('ucp', $payload) && ! is_array($payload['ucp'])) {
            throw new ValidationException('Platform profile "ucp" must be an object.');
        }

        return is_array($payload['ucp'] ?? null) ? $payload['ucp'] : $payload;
    }

    /**
     * @param array<string, mixed> $root
     */
    private static function version(array $root): string
    {
        $version = $root['version'] ?? null;
        if (! is_string($version) || trim($version) === '') {
            throw new ValidationException('Platform profile version must be a non-empty string.');
        }

        return $version;
    }

    /**
     * @param array<string, mixed> $root
     * @param array<string, mixed> $payload
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function entrySection(array $root, array $payload, string $name): array
    {
        $section = $root[$name] ?? $payload[$name] ?? [];

        // `toArray()` emits an empty section as `stdClass` so it encodes as `{}` rather than
        // `[]`, which is what the schema says it is. Accepting only arrays here meant a profile
        // this class produced could not be read back by this class -- the round trip the
        // discovery cache and the profile builder both depend on.
        if ($section instanceof \stdClass) {
            $section = (array) $section;
        }

        if (! is_array($section)) {
            throw new ValidationException(sprintf('Platform profile section "%s" must be an object.', $name));
        }

        $normalized = [];
        foreach ($section as $sectionName => $entries) {
            if (! is_string($sectionName) || trim($sectionName) === '') {
                throw new ValidationException(sprintf('Platform profile section "%s" keys must be non-empty strings.', $name));
            }

            if (! is_array($entries) || ! array_is_list($entries)) {
                throw new ValidationException(sprintf('Platform profile section "%s" entry "%s" must be a list.', $name, $sectionName));
            }

            $normalizedEntries = [];
            foreach ($entries as $index => $entry) {
                if (! is_array($entry)) {
                    throw new ValidationException(sprintf('Platform profile section "%s" entry "%s" at index %d must be an object.', $name, $sectionName, $index));
                }

                $normalizedEntries[] = $entry;
            }

            $normalized[$sectionName] = $normalizedEntries;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<PublicSigningKey>
     */
    private static function signingKeys(array $payload): array
    {
        // `keys` since 2026-08-25; `signing_keys` is read for one release so a peer that has not
        // moved yet still parses. Named `$field` in the errors so a reader is told which of the
        // two they actually sent.
        $field = array_key_exists('keys', $payload) ? 'keys' : 'signing_keys';
        $entries = $payload[$field] ?? [];
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new ValidationException(sprintf('Platform profile "%s" must be a list.', $field));
        }

        $seen = [];
        $signingKeys = [];
        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                throw new ValidationException(sprintf('Platform profile signing key at index %d must be an object.', $index));
            }

            // Skipped rather than fatal. The spec requires a verifier to tolerate key types it
            // does not recognise and to select by kid, so one Ed25519 key must not cost us the
            // EC key sitting next to it -- which is what happened while this called fromJwk().
            $key = PublicSigningKey::tryFromJwk($entry);
            if ($key === null) {
                continue;
            }

            if (isset($seen[$key->kid])) {
                throw new ValidationException(sprintf('Platform profile signing key id "%s" is duplicated.', $key->kid));
            }

            $seen[$key->kid] = true;
            $signingKeys[] = $key;
        }

        return $signingKeys;
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

        // `toArray()` emits an empty section as `stdClass` so it encodes as `{}` rather than
        // `[]`, which is what the schema says it is. Accepting only arrays here meant a profile
        // this class produced could not be read back by this class -- the round trip the
        // discovery cache and the profile builder both depend on.
        if ($section instanceof \stdClass) {
            $section = (array) $section;
        }

        if (! is_array($section)) {
            throw new ValidationException(sprintf('Platform profile section "%s" must be an object.', $name));
        }

        return $section;
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
            if (trim($key) === '' || ! is_string($value) || trim($value) === '') {
                throw new ValidationException('Platform profile supported_versions must be a map of non-empty strings.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
