<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

final class Buyer
{
    public function __construct(
        public readonly ?string $email = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $phoneNumber = null,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'email' => $this->email,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'phone_number' => $this->phoneNumber,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
