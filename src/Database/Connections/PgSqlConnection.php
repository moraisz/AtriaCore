<?php

declare(strict_types=1);

namespace Atria\Database\Connections;

use Atria\Database\AbstractClasses\PdoConnection;
use PDOException;

class PgSqlConnection extends PdoConnection
{
    protected function dsn(): string
    {
        $host = $this->configString('host') ?? '';
        $port = $this->configString('port') ?? '';
        $database = $this->configString('database') ?? '';
        $username = $this->configString('username') ?? '';
        $password = $this->configString('password') ?? '';

        if ($host === '' || $port === '' || $database === '' || $username === '' || $password === '') {
            throw new PDOException('Missing required database connection parameters');
        }

        return sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database);
    }
}
