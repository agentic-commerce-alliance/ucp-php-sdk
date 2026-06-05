<?php

declare(strict_types=1);

use MerchantSymfonyApp\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$kernel = new Kernel('dev', false);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
