<?php

declare(strict_types=1);

namespace Atria\Modules\Auth\Migrations;

use Atria\Database\AbstractClasses\Migration;

return new class extends Migration {
    public function up(): void
    {
        $this->queryBuilder
            ->createTable('users', [
                'id' => 'SERIAL PRIMARY KEY',
                'name' => 'VARCHAR(100)',
                'email' => 'VARCHAR(100) UNIQUE NOT NULL',
                'password_hash' => 'VARCHAR(255) NOT NULL',
                'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            ])
            ->execute();
    }

    public function down(): void
    {
        $this->queryBuilder
            ->dropTable('users')
            ->execute();
    }
};
