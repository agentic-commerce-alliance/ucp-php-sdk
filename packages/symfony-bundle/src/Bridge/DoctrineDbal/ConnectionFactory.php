<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DoctrineDbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

final class ConnectionFactory
{
    public static function create(string $dsn): Connection
    {
        $parser = new DsnParser([
            'sqlite' => 'pdo_sqlite',
            'postgres' => 'pdo_pgsql',
            'mysql' => 'pdo_mysql',
        ]);

        return DriverManager::getConnection($parser->parse($dsn));
    }
}
