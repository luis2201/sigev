<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->id();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->dateTime('created_at', 6);

            $table->unique('code', 'uq_roles_code');
        });

        // MariaDB SQL is needed because Blueprint has no native CHECK API.
        DB::statement("ALTER TABLE `roles` ADD CONSTRAINT `ck_roles_code` CHECK (`code` IN ('admin', 'coordinator', 'tutor', 'student'))");
        DB::statement('ALTER TABLE `roles` ADD CONSTRAINT `ck_roles_name` CHECK (CHAR_LENGTH(TRIM(`name`)) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
