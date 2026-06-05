<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface EmbeddedPageRendererInterface
{
    public function render(string $type, string $id, Request $request): ?Response;
}
