<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Protocol;

interface UcpOperationPayload extends \JsonSerializable
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
