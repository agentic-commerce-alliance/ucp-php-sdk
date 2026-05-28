<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter\Model;

use Ucp\Sdk\Model\Common\Buyer;

final readonly class BuyerRecord
{
    public function __construct(
        public ?string $email = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $phoneNumber = null,
    ) {
    }

    public function toBuyer(): Buyer
    {
        return new Buyer($this->email, $this->firstName, $this->lastName, $this->phoneNumber);
    }
}
