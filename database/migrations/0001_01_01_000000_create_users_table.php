<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->id();
            $table->string('name', 200);
            $table->string('identification_type', 20);
            $table->string('identification_number', 30);
            $table->string('email', 254);
            $table->dateTime('email_verified_at', 6)->nullable();
            $table->string('password', 255)->comment('Hash only; hashing is enforced by the application.');
            $table->unsignedTinyInteger('must_change_password')->default(1);
            $table->dateTime('password_changed_at', 6)->nullable();
            $table->string('status', 20)->default('active');
            $table->rememberToken();
            $table->dateTime('last_login_at', 6)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            // UTC instants are supplied by the application, not by a session-dependent SQL default.
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique('email', 'uq_users_email');
            $table->unique(['identification_type', 'identification_number'], 'uq_users_identification');
            $table->index('status', 'ix_users_status');
            $table->index('created_by', 'ix_users_created_by');
            $table->foreign('created_by', 'fk_users_created_by')
                ->references('id')->on('users')
                ->restrictOnUpdate()->restrictOnDelete();
        });

        // MariaDB CHECK constraints use explicit SQL; Blueprint has no native CHECK API.
        DB::statement("ALTER TABLE `users` ADD CONSTRAINT `ck_users_password_change` CHECK (`must_change_password` IN (0, 1))");
        DB::statement("ALTER TABLE `users` ADD CONSTRAINT `ck_users_status` CHECK (`status` IN ('active', 'suspended', 'inactive'))");
        DB::statement("ALTER TABLE `users` ADD CONSTRAINT `ck_users_required_text` CHECK (CHAR_LENGTH(TRIM(`name`)) > 0 AND CHAR_LENGTH(TRIM(`identification_type`)) > 0 AND CHAR_LENGTH(TRIM(`identification_number`)) > 0 AND CHAR_LENGTH(TRIM(`email`)) > 0 AND CHAR_LENGTH(TRIM(`password`)) > 0)");

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
