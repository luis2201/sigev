<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            // Pending FK: academic_period_id -> academic_periods.id, RESTRICT both ways.
            // Add it in a separately authorized migration AFTER academic_periods exists,
            // checking existing non-null values first; its down() must drop only that FK.
            $table->unsignedBigInteger('academic_period_id')->nullable();
            $table->string('action', 100);
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->char('request_id', 36)->collation('utf8mb4_bin');
            // Future writers must allowlist metadata and exclude secrets and unnecessary personal data.
            $table->json('changes')->nullable();
            $table->text('reason')->nullable();
            $table->dateTime('occurred_at', 6);
            $table->dateTime('created_at', 6);

            $table->index(['academic_period_id', 'occurred_at'], 'ix_audit_events_period_time');
            $table->index(['entity_type', 'entity_id', 'occurred_at'], 'ix_audit_events_entity_time');
            $table->index(['actor_id', 'occurred_at'], 'ix_audit_events_actor_time');
            $table->index('request_id', 'ix_audit_events_request');

            $table->foreign('actor_id', 'fk_audit_events_actor')
                ->references('id')->on('users')
                ->restrictOnUpdate()->restrictOnDelete();
            // entity_type/entity_id is deliberately polymorphic, without a foreign key.
        });

        // Metadata filtering and append-only access belong to future services/permissions.
        DB::statement('ALTER TABLE `audit_events` ADD CONSTRAINT `ck_audit_events_required_text` CHECK (CHAR_LENGTH(TRIM(`action`)) > 0 AND CHAR_LENGTH(TRIM(`entity_type`)) > 0 AND CHAR_LENGTH(TRIM(`request_id`)) > 0)');
        DB::statement('ALTER TABLE `audit_events` ADD CONSTRAINT `ck_audit_events_reason` CHECK (`reason` IS NULL OR CHAR_LENGTH(TRIM(`reason`)) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
