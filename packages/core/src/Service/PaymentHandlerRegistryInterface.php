<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Contract\PaymentHandlerInterface;

interface PaymentHandlerRegistryInterface
{
    /**
     * @return list<PaymentHandlerInterface>
     */
    public function all(): array;

    public function find(string $name): ?PaymentHandlerInterface;
}
