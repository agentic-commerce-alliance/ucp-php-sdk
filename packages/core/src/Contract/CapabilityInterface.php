<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Profile\CapabilityDescriptor;

interface CapabilityInterface
{
    public function describe(): CapabilityDescriptor;
}
