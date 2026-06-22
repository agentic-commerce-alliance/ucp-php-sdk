<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Http;

interface HttpResponseChunkInterface
{
    public function isTimeout(): bool;

    public function isFirst(): bool;

    public function getContent(): string;
}
