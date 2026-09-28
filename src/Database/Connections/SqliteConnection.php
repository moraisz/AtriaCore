<?php

declare(strict_types=1);

namespace Atria\Database\Connections;

use Atria\Database\AbstractClasses\PdoConnection;
use PDO;
use PDOException;

class SqliteConnection extends PdoConnection
{
    protected function dsn(): string
    {
        $database = $this->configString('database') ?? '';

        if ($database === '') {
            throw new PDOException('Missing required database connection parameters');
        }

        return 'sqlite:' . $database;
    }

    protected function createPdo(): PDO
    {
        return new PDO($this->dsn(), null, null, $this->options());
    }

    protected function configure(PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}
