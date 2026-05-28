<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

final readonly class Signals
{
    /**
     * @param array<string, scalar|null> $values
     */
    public function __construct(
        public array $values = [],
    ) {
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
