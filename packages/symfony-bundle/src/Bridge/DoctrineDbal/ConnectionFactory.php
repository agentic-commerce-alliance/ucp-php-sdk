<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DoctrineDbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/** @internal */
final class ConnectionFactory
{
    public static function create(string $dsn): Connection
    {
        // DsnParser has existed since DBAL 3.6 and this package requires ^3.7 || ^4.0, so
        // the class_exists() guard that used to be here could never take its other branch.
        // A fallback that cannot run is worse than no fallback: it reads as support for a
        // configuration nothing tests.
        $parser = new DsnParser([
            'sqlite' => 'pdo_sqlite',
            'postgres' => 'pdo_pgsql',
            'mysql' => 'pdo_mysql',
        ]);

        return DriverManager::getConnection($parser->parse($dsn));
    }
}
