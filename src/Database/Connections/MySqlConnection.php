<?php

declare(strict_types=1);

namespace Atria\Database\Connections;

use Atria\Database\AbstractClasses\PdoConnection;
use PDOException;
use Pdo\Mysql;

class MySqlConnection extends PdoConnection
{
    /**
     * Reports matched instead of changed rows, so affected() agrees with the
     * other drivers when an UPDATE writes an unchanged value.
     */
    protected function options(): array
    {
        return parent::options() + [Mysql::ATTR_FOUND_ROWS => true];
    }

    protected function dsn(): string
    {
        $host = $this->configString('host') ?? '';
        $port = $this->configString('port') ?? '';
        $database = $this->configString('database') ?? '';
        $username = $this->configString('username') ?? '';
        $password = $this->configString('password') ?? '';
        $charset = $this->configString('charset') ?? '';

        if ($host === '' || $port === '' || $database === '' || $username === '' || $password === '') {
            throw new PDOException('Missing required database connection parameters');
        }

        return sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset !== '' ? $charset : 'utf8mb4',
        );
    }
}
