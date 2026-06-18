<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DoctrineDbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/** @internal */
final class ConnectionFactory
{
    public static function create(string $dsn): Connection
    {
        if (class_exists(\Doctrine\DBAL\Tools\DsnParser::class)) {
            $parser = new \Doctrine\DBAL\Tools\DsnParser([
                'sqlite' => 'pdo_sqlite',
                'postgres' => 'pdo_pgsql',
                'mysql' => 'pdo_mysql',
            ]);

            return DriverManager::getConnection($parser->parse($dsn));
        }

        return DriverManager::getConnection(['url' => $dsn]);
    }
}
