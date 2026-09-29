<?php

declare(strict_types=1);

namespace Atria\Modules\Auth\Migrations;

use Atria\Database\AbstractClasses\Migration;
use Atria\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        $this->schema->create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->nullable();
            $table->string('email', 100)->unique();
            $table->string('password_hash');
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    public function down(): void
    {
        $this->schema->drop('users');
    }
};
