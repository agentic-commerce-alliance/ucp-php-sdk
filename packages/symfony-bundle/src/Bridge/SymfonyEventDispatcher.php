<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;
use Ucp\Sdk\Service\EventDispatcherInterface;

/** @internal */
final class SymfonyEventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private readonly SymfonyEventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function dispatch(object $event): object
    {
        return $this->eventDispatcher->dispatch($event);
    }
}
