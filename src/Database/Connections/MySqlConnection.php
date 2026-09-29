<?php

declare(strict_types=1);

namespace Atria\Database\Connections;

use Atria\Database\AbstractClasses\PooledConnection;
use Atria\Database\Contracts\ConnectionLink;

/**
 * MySQL and MariaDB through ext-mysqli. Queries inside Async::concurrently() run at
 * the same time on separate connections; see MySqlLink for the details.
 */
class MySqlConnection extends PooledConnection
{
    private ?MySqlPoller $poller = null;

    protected function requiredExtension(): string
    {
        return 'mysqli';
    }

    protected function openLink(): ConnectionLink
    {
        $config = $this->requireConfig(['host', 'port', 'database', 'username', 'password']);
        $charset = $this->configString('charset') ?? '';

        return MySqlLink::open(
            [
                'host' => $config['host'],
                'port' => $config['port'],
                'database' => $config['database'],
                'username' => $config['username'],
                'password' => $config['password'],
                'charset' => $charset !== '' ? $charset : 'utf8mb4',
            ],
            $this->loop,
            $this->poller(),
            $this->now(),
        );
    }

    public function reset(): void
    {
        $this->poller?->reset();

        parent::reset();
    }

    private function poller(): MySqlPoller
    {
        return $this->poller ??= new MySqlPoller($this->loop);
    }
}
