<?php

declare(strict_types=1);

namespace Atria\Modules\Auth\Migrations;

use Atria\Database\AbstractClasses\Migration;
use Atria\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        $this->schema->create('refresh_tokens', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->references('users')->cascadeOnDelete();
            $table->string('token_hash')->unique();
            $table->string('device_info')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->index(['user_id'], 'idx_refresh_tokens_user_id');
        });
    }

    public function down(): void
    {
        $this->schema->drop('refresh_tokens');
    }
};
