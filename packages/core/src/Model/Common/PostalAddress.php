<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

/**
 * A postal address as defined by the shared UCP `postal_address` type. All fields are optional;
 * used here for a payment instrument's billing address.
 *
 * Reference: https://ucp.dev/schemas/shopping/types/postal_address.json
 */
final class PostalAddress
{
    public function __construct(
        public readonly ?string $streetAddress = null,
        public readonly ?string $extendedAddress = null,
        public readonly ?string $addressLocality = null,
        public readonly ?string $addressRegion = null,
        public readonly ?string $addressCountry = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $phoneNumber = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $string = static fn (string $key): ?string => isset($payload[$key]) && is_string($payload[$key]) ? $payload[$key] : null;

        return new self(
            $string('street_address'),
            $string('extended_address'),
            $string('address_locality'),
            $string('address_region'),
            $string('address_country'),
            $string('postal_code'),
            $string('first_name'),
            $string('last_name'),
            $string('phone_number'),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'street_address' => $this->streetAddress,
            'extended_address' => $this->extendedAddress,
            'address_locality' => $this->addressLocality,
            'address_region' => $this->addressRegion,
            'address_country' => $this->addressCountry,
            'postal_code' => $this->postalCode,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'phone_number' => $this->phoneNumber,
        ], static fn (?string $value): bool => $value !== null);
    }
}
