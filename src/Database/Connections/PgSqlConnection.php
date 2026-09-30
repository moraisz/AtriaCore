<?php

declare(strict_types=1);

namespace Atria\Database\Connections;

use Atria\Database\AbstractClasses\PooledConnection;
use Atria\Database\Contracts\ConnectionLink;

/**
 * PostgreSQL through ext-pgsql. Connecting and waiting for results never
 * block the thread, so queries inside Async::concurrently() run at the same time.
 */
class PgSqlConnection extends PooledConnection
{
    protected function requiredExtension(): string
    {
        return 'pgsql';
    }

    protected function openLink(): ConnectionLink
    {
        return PgSqlLink::open($this->dsn(), $this->loop, $this->now());
    }

    protected function prepareSql(string $query): string
    {
        return PgSqlPlaceholders::convert($query);
    }

    private function dsn(): string
    {
        $config = $this->requireConfig(['host', 'port', 'database', 'username', 'password']);
        $names = ['host' => 'host', 'port' => 'port', 'database' => 'dbname', 'username' => 'user', 'password' => 'password'];

        $parts = [];
        foreach ($names as $key => $name) {
            $parts[] = $name . "='" . addcslashes($config[$key], "'\\") . "'";
        }

        return implode(' ', $parts);
    }
}
