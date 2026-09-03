<?php

declare(strict_types=1);

use MerchantSymfonyApp\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// Read the environment rather than hardcoding dev. A conformance run has to boot this the way
// a merchant would -- prod, debug off, no developer affordances -- because the affordances are
// exactly what would make it pass for the wrong reasons.
$environment = (string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev');
$debug = filter_var(
    $_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? ($environment === 'dev'),
    FILTER_VALIDATE_BOOL,
);

$kernel = new Kernel($environment, $debug);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
