<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Registry;

use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/** @internal */
final class PaymentHandlerRegistry implements PaymentHandlerRegistryInterface
{
    /**
     * @param iterable<PaymentHandlerInterface> $handlers
     */
    public function __construct(
        iterable $handlers,
    ) {
        $this->handlers = array_values(iterator_to_array($handlers));
        $this->handlersById = [];

        foreach ($this->handlers as $handler) {
            $id = $handler->id();
            if (isset($this->handlersById[$id])) {
                throw new \InvalidArgumentException(sprintf('Payment handler "%s" is registered more than once.', $id));
            }

            $this->handlersById[$id] = $handler;
        }
    }

    /** @var list<PaymentHandlerInterface> */
    private array $handlers;

    /** @var array<string, PaymentHandlerInterface> */
    private array $handlersById;

    public function all(): array
    {
        return $this->handlers;
    }

    public function find(string $name): ?PaymentHandlerInterface
    {
        return $this->handlersById[$name] ?? null;
    }
}
