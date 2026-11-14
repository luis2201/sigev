<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_days', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('participation_id');
            $table->date('local_date');
            $table->string('status', 25)->default('in_progress');
            $table->unsignedBigInteger('current_review_id')->nullable();
            $table->dateTime('closed_at', 6)->nullable();
            $table->unsignedInteger('revision_number')->default(1);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['participation_id', 'local_date'], 'uq_att_0_0');
            $table->unique(['id', 'participation_id'], 'uq_att_0_1');
            $table->unique('current_review_id');
            $table->foreign('participation_id', 'fk_att_0_1')->references('id')->on('participations')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('attendance_intervals', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('attendance_day_id');
            $table->unsignedBigInteger('participation_id');
            $table->unsignedBigInteger('participation_configuration_id');
            $table->unsignedBigInteger('placement_configuration_id');
            $table->unsignedBigInteger('schedule_interval_id');
            $table->dateTime('authorized_start_at', 6);
            $table->dateTime('authorized_end_at', 6);
            $table->dateTime('actual_entry_at', 6);
            $table->dateTime('actual_exit_at', 6)->nullable();
            $table->dateTime('provisional_closed_at', 6)->nullable();
            $table->string('closure_type', 30)->nullable();
            $table->string('mark_status', 25)->default('open');
            $table->decimal('computable_minutes', 10, 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->uuid('entry_request_key')->unique();
            $table->uuid('exit_request_key')->nullable()->unique();
            $table->unsignedBigInteger('open_participation_id')->nullable()->storedAs("CASE WHEN mark_status = 'open' THEN participation_id ELSE NULL END")->unique('uq_att_open_participation');
            $table->unique(['id', 'attendance_day_id'], 'uq_att_interval_day');
            $table->unique(['attendance_day_id', 'schedule_interval_id'], 'uq_att_1_0');
            $table->foreign('attendance_day_id', 'fk_att_1_1')->references('id')->on('attendance_days')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('participation_id', 'fk_att_1_2')->references('id')->on('participations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('participation_configuration_id', 'fk_att_1_3')->references('id')->on('participation_configurations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('placement_configuration_id', 'fk_att_1_4')->references('id')->on('placement_configurations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('schedule_interval_id', 'fk_att_1_5')->references('id')->on('schedule_intervals')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('activities', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('attendance_interval_id');
            $table->text('description');
            $table->dateTime('registered_at', 6);
            $table->unsignedBigInteger('created_by');
            $table->string('status', 20)->default('draft');
            $table->dateTime('created_at', 6);
            $table->foreign('attendance_interval_id', 'fk_att_2_1')->references('id')->on('attendance_intervals')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'fk_att_2_4')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('activity_evidences', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('activity_id');
            $table->unsignedBigInteger('private_file_id');
            $table->dateTime('created_at', 6);
            $table->unique('activity_id');
            $table->unique('private_file_id');
            $table->foreign('activity_id', 'fk_att_3_1')->references('id')->on('activities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('private_file_id', 'fk_att_3_2')->references('id')->on('private_files')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('attendance_incidents', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('attendance_day_id');
            $table->unsignedBigInteger('attendance_interval_id')->nullable();
            $table->string('kind', 30);
            $table->text('observation');
            $table->text('student_justification')->nullable();
            $table->unsignedBigInteger('support_file_id')->nullable();
            $table->string('support_reference', 1000)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->foreign('attendance_day_id', 'fk_att_4_1')->references('id')->on('attendance_days')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('attendance_interval_id', 'fk_att_4_2')->references('id')->on('attendance_intervals')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('support_file_id', 'fk_att_4_6')->references('id')->on('private_files')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('recorded_by', 'fk_att_4_8')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('attendance_reviews', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('attendance_day_id');
            $table->unsignedBigInteger('participation_id');
            $table->unsignedBigInteger('tutor_assignment_id');
            $table->unsignedBigInteger('reviewed_by');
            $table->unsignedInteger('sequence_number');
            $table->string('decision', 20);
            $table->text('justification')->nullable();
            $table->unsignedBigInteger('support_file_id')->nullable();
            $table->string('support_reference', 1000)->nullable();
            $table->decimal('approved_minutes', 10, 6)->default(0);
            $table->decimal('computable_minutes_snapshot', 10, 6);
            $table->unsignedInteger('day_revision_number');
            $table->dateTime('reviewed_at', 6);
            $table->dateTime('created_at', 6);
            $table->uuid('request_key')->unique();
            $table->unique(['attendance_day_id', 'sequence_number'], 'uq_att_5_0');
            $table->unique(['id', 'attendance_day_id'], 'uq_att_5_1');
            $table->foreign('attendance_day_id', 'fk_att_5_1')->references('id')->on('attendance_days')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('participation_id', 'fk_att_5_2')->references('id')->on('participations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('tutor_assignment_id', 'fk_att_5_3')->references('id')->on('tutor_assignments')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('reviewed_by', 'fk_att_5_4')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('support_file_id', 'fk_att_5_8')->references('id')->on('private_files')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('attendance_events', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('attendance_day_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('from_status', 25)->nullable();
            $table->string('to_status', 25);
            $table->text('reason')->nullable();
            $table->dateTime('occurred_at', 6);
            $table->dateTime('created_at', 6);
            $table->foreign('attendance_day_id', 'fk_att_6_1')->references('id')->on('attendance_days')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('actor_id', 'fk_att_6_2')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement("ALTER TABLE `attendance_days` ADD CONSTRAINT `ck_att_0_0` CHECK (status IN ('in_progress','pending','observed','approved','exceptional','rejected'))");
        DB::statement('ALTER TABLE `attendance_days` ADD CONSTRAINT `ck_att_0_1` CHECK (revision_number > 0)');
        DB::statement("ALTER TABLE `attendance_days` ADD CONSTRAINT `ck_att_0_2` CHECK ((status='in_progress' AND closed_at IS NULL) OR (status <> 'in_progress' AND closed_at IS NOT NULL))");
        DB::statement('ALTER TABLE `attendance_intervals` ADD CONSTRAINT `ck_att_1_0` CHECK (authorized_end_at > authorized_start_at)');
        DB::statement('ALTER TABLE `attendance_intervals` ADD CONSTRAINT `ck_att_1_1` CHECK (actual_exit_at IS NULL OR actual_exit_at >= actual_entry_at)');
        DB::statement("ALTER TABLE `attendance_intervals` ADD CONSTRAINT `ck_att_1_2` CHECK ((mark_status='open' AND actual_exit_at IS NULL AND provisional_closed_at IS NULL AND closure_type IS NULL AND computable_minutes IS NULL) OR (mark_status='manual_closed' AND actual_exit_at IS NOT NULL AND provisional_closed_at IS NULL AND closure_type='manual' AND computable_minutes IS NOT NULL) OR (mark_status='provisional_closed' AND actual_exit_at IS NULL AND provisional_closed_at IS NOT NULL AND closure_type='automatic_provisional' AND computable_minutes IS NOT NULL))");
        DB::statement('ALTER TABLE `attendance_intervals` ADD CONSTRAINT `ck_att_1_3` CHECK (computable_minutes IS NULL OR (computable_minutes >= 0 AND computable_minutes <= ROUND(TIMESTAMPDIFF(MICROSECOND,authorized_start_at,authorized_end_at)/60000000,6)))');
        DB::statement("ALTER TABLE `activities` ADD CONSTRAINT `ck_att_2_0` CHECK (status IN ('draft','complete','void'))");
        DB::statement('ALTER TABLE `activities` ADD CONSTRAINT `ck_att_2_1` CHECK (CHAR_LENGTH(TRIM(description)) > 0)');
        DB::statement("ALTER TABLE `attendance_incidents` ADD CONSTRAINT `ck_att_4_0` CHECK (kind IN ('missing_exit','no_activities','mark_error','other'))");
        DB::statement("ALTER TABLE `attendance_reviews` ADD CONSTRAINT `ck_att_5_0` CHECK (decision IN ('ordinary','exceptional','observed','rejected','reopened'))");
        DB::statement('ALTER TABLE `attendance_reviews` ADD CONSTRAINT `ck_att_5_1` CHECK (approved_minutes >= 0 AND approved_minutes <= computable_minutes_snapshot)');
        DB::statement("ALTER TABLE `attendance_reviews` ADD CONSTRAINT `ck_att_5_2` CHECK (decision IN ('ordinary','exceptional') OR approved_minutes=0)");
        DB::statement("ALTER TABLE `attendance_reviews` ADD CONSTRAINT `ck_att_5_3` CHECK (decision <> 'exceptional' OR (CHAR_LENGTH(TRIM(justification)) > 0 AND (support_file_id IS NOT NULL OR CHAR_LENGTH(TRIM(support_reference)) > 0)))");
        DB::statement('ALTER TABLE `attendance_reviews` ADD CONSTRAINT `ck_att_5_4` CHECK (sequence_number > 0 AND day_revision_number > 0)');
        DB::statement("ALTER TABLE `attendance_events` ADD CONSTRAINT `ck_att_6_0` CHECK (to_status IN ('in_progress','pending','observed','approved','exceptional','rejected'))");
        Schema::table('participation_configurations', function (Blueprint $table): void {
            $table->unique(['id', 'placement_configuration_id'], 'uq_att_application_config');
        });
        Schema::table('attendance_intervals', function (Blueprint $table): void {
            $table->foreign(['attendance_day_id', 'participation_id'], 'fk_att_composite_0')->references(['id', 'participation_id'])->on('attendance_days')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('attendance_intervals', function (Blueprint $table): void {
            $table->foreign(['participation_configuration_id', 'participation_id'], 'fk_att_composite_1')->references(['id', 'participation_id'])->on('participation_configurations')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('attendance_intervals', function (Blueprint $table): void {
            $table->foreign(['participation_configuration_id', 'placement_configuration_id'], 'fk_att_composite_2')->references(['id', 'placement_configuration_id'])->on('participation_configurations')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('attendance_intervals', function (Blueprint $table): void {
            $table->foreign(['schedule_interval_id', 'placement_configuration_id'], 'fk_att_composite_3')->references(['id', 'placement_configuration_id'])->on('schedule_intervals')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('attendance_reviews', function (Blueprint $table): void {
            $table->foreign(['attendance_day_id', 'participation_id'], 'fk_att_composite_4')->references(['id', 'participation_id'])->on('attendance_days')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('attendance_reviews', function (Blueprint $table): void {
            $table->foreign(['tutor_assignment_id', 'participation_id'], 'fk_att_composite_5')->references(['id', 'participation_id'])->on('tutor_assignments')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('attendance_incidents', function (Blueprint $table): void {
            $table->foreign(['attendance_interval_id', 'attendance_day_id'], 'fk_att_composite_6')->references(['id', 'attendance_day_id'])->on('attendance_intervals')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('attendance_days', function (Blueprint $table): void {
            $table->foreign(['current_review_id', 'id'], 'fk_att_composite_7')->references(['id', 'attendance_day_id'])->on('attendance_reviews')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('attendance_days', function (Blueprint $table): void {
            $table->index(['status', 'local_date'], 'ix_att_0');
        });
        Schema::table('attendance_intervals', function (Blueprint $table): void {
            $table->index(['participation_id', 'mark_status'], 'ix_att_1');
        });
        Schema::table('attendance_intervals', function (Blueprint $table): void {
            $table->index(['mark_status', 'authorized_end_at'], 'ix_att_2');
        });
        Schema::table('activities', function (Blueprint $table): void {
            $table->index(['attendance_interval_id', 'registered_at'], 'ix_att_3');
        });
        Schema::table('attendance_incidents', function (Blueprint $table): void {
            $table->index(['attendance_day_id', 'kind'], 'ix_att_4');
        });
        Schema::table('attendance_reviews', function (Blueprint $table): void {
            $table->index(['tutor_assignment_id', 'reviewed_at'], 'ix_att_5');
        });
        Schema::table('attendance_events', function (Blueprint $table): void {
            $table->index(['attendance_day_id', 'occurred_at'], 'ix_att_6');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table): void {
            $table->dropForeign('fk_att_composite_7');
        });
        Schema::dropIfExists('attendance_events');
        Schema::dropIfExists('attendance_reviews');
        Schema::dropIfExists('attendance_incidents');
        Schema::dropIfExists('activity_evidences');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('attendance_intervals');
        Schema::dropIfExists('attendance_days');
        Schema::table('participation_configurations', function (Blueprint $table): void {
            $table->dropUnique('uq_att_application_config');
        });
    }
};
