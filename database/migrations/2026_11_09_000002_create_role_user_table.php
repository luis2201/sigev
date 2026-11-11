<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_user', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('assigned_by');
            $table->dateTime('assigned_at', 6);
            $table->dateTime('revoked_at', 6)->nullable();
            $table->string('reason', 1000)->nullable();
            $table->dateTime('created_at', 6);

            $table->unique(['role_id', 'user_id'], 'uq_role_user_role_user');
            $table->index(['user_id', 'revoked_at'], 'ix_role_user_user_revoked');
            $table->index('assigned_by', 'ix_role_user_assigned_by');

            $table->foreign('role_id', 'fk_role_user_role')
                ->references('id')->on('roles')
                ->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('user_id', 'fk_role_user_user')
                ->references('id')->on('users')
                ->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('assigned_by', 'fk_role_user_assigned_by')
                ->references('id')->on('users')
                ->restrictOnUpdate()->restrictOnDelete();
        });

        // One pivot per role/user; later services must audit revocation and reactivation.
        // Coordinator exclusivity requires a transaction locking the coordinator role row.
        // CHECK cannot enforce that cross-row rule; it only validates this row's dates.
        DB::statement('ALTER TABLE `role_user` ADD CONSTRAINT `ck_role_user_revocation` CHECK (`revoked_at` IS NULL OR `revoked_at` >= `assigned_at`)');
        DB::statement('ALTER TABLE `role_user` ADD CONSTRAINT `ck_role_user_reason` CHECK (`reason` IS NULL OR CHAR_LENGTH(TRIM(`reason`)) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
    }
};
