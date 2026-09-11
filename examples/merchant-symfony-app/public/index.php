<?php

declare(strict_types=1);

use MerchantSymfonyApp\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/**
 * `getenv()` too. Under the built-in server an exported variable reaches neither `$_ENV`
 * nor `$_SERVER` -- `variables_order` governs the first and CGI-style population the
 * second -- so reading only those two silently resolved `APP_ENV=prod` to `dev`, which is
 * how the conformance lane came to run with development-mode profile fetching enabled.
 * `Kernel` already reads its own variables this way; this is the one that decides whether
 * those affordances exist at all.
 */
function ucp_merchant_env(string $name): ?string
{
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

    return is_string($value) && $value !== '' ? $value : null;
}

// Read the environment rather than hardcoding dev. A conformance run has to boot this the way
// a merchant would -- prod, debug off, no developer affordances -- because the affordances are
// exactly what would make it pass for the wrong reasons.
$environment = ucp_merchant_env('APP_ENV') ?? 'dev';
$debug = filter_var(
    ucp_merchant_env('APP_DEBUG') ?? ($environment === 'dev'),
    FILTER_VALIDATE_BOOL,
);

$kernel = new Kernel($environment, $debug);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
