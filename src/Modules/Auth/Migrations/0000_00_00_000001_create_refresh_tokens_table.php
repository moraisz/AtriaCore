<?php

declare(strict_types=1);

namespace Atria\Modules\Auth\Migrations;

use Atria\Database\AbstractClasses\Migration;

return new class extends Migration {
    public function up(): void
    {
        $this->queryBuilder
            ->createTable('refresh_tokens', [
                'id' => 'SERIAL PRIMARY KEY',
                'user_id' => 'INT NOT NULL REFERENCES users(id) ON DELETE CASCADE',
                'token_hash' => 'VARCHAR(255) UNIQUE NOT NULL',
                'device_info' => 'VARCHAR(255)',
                'expires_at' => 'TIMESTAMP NOT NULL',
                'revoked_at' => 'TIMESTAMP',
                'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            ])
            ->execute();

        $this->queryBuilder
            ->createIndex('idx_refresh_tokens_user_id', 'refresh_tokens', ['user_id']);
    }

    public function down(): void
    {
        $this->queryBuilder
            ->dropTable('refresh_tokens')
            ->execute();
    }
};
