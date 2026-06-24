<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

interface EventDispatcherInterface
{
    public function dispatch(object $event): object;
}
