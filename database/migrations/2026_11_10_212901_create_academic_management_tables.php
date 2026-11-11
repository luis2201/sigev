<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('careers', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->string('code', 40);
            $table->string('name', 200);
            $table->string('status', 20)->default('active');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['code'], 'uq_a006_careers_0');
            $table->index(['status'], 'ix_a006_careers_0');
        });
        DB::statement("ALTER TABLE `careers` ADD CONSTRAINT `ck_a006_careers_0` CHECK (status IN ('active','inactive'))");
        DB::statement('ALTER TABLE `careers` ADD CONSTRAINT `ck_a006_careers_1` CHECK (CHAR_LENGTH(TRIM(code)) > 0 AND CHAR_LENGTH(TRIM(name)) > 0)');
        Schema::create('academic_periods', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('planned');
            $table->unsignedBigInteger('created_by');
            $table->dateTime('closed_at', 6)->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['code'], 'uq_a006_academic_period_0');
            $table->index(['status', 'starts_on'], 'ix_a006_academic_period_0');
            $table->foreign('created_by', 'fk_a006_academic_period_created_by')->references('id')->on('users')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('closed_by', 'fk_a006_academic_period_closed_by')->references('id')->on('users')->restrictOnUpdate()->restrictOnDelete();
        });
        DB::statement('ALTER TABLE `academic_periods` ADD CONSTRAINT `ck_a006_academic_periods_0` CHECK (ends_on >= starts_on)');
        DB::statement("ALTER TABLE `academic_periods` ADD CONSTRAINT `ck_a006_academic_periods_1` CHECK (status IN ('planned','active','closed'))");
        DB::statement("ALTER TABLE `academic_periods` ADD CONSTRAINT `ck_a006_academic_periods_2` CHECK ((status = 'closed' AND closed_at IS NOT NULL AND closed_by IS NOT NULL) OR (status <> 'closed' AND closed_at IS NULL AND closed_by IS NULL))");
        Schema::create('teachers', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('academic_title', 150)->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('created_by');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['user_id'], 'uq_a006_teachers_0');
            $table->index(['status'], 'ix_a006_teachers_0');
            $table->foreign('user_id', 'fk_a006_teachers_user_id')->references('id')->on('users')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('created_by', 'fk_a006_teachers_created_by')->references('id')->on('users')->restrictOnUpdate()->restrictOnDelete();
        });
        DB::statement("ALTER TABLE `teachers` ADD CONSTRAINT `ck_a006_teachers_0` CHECK (status IN ('active','inactive'))");
        Schema::create('students', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('internal_code', 50)->nullable();
            $table->string('status', 20)->default('active');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['user_id'], 'uq_a006_students_0');
            $table->unique(['internal_code'], 'uq_a006_students_1');
            $table->index(['status'], 'ix_a006_students_0');
            $table->foreign('user_id', 'fk_a006_students_user_id')->references('id')->on('users')->restrictOnUpdate()->restrictOnDelete();
        });
        DB::statement("ALTER TABLE `students` ADD CONSTRAINT `ck_a006_students_0` CHECK (status IN ('active','inactive'))");
        Schema::create('student_career_records', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('career_id');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->unsignedBigInteger('recorded_by');
            $table->string('reason', 1000);
            $table->dateTime('created_at', 6);
            $table->unique(['student_id', 'career_id', 'valid_from'], 'uq_a006_student_career__0');
            $table->unique(['id', 'student_id', 'career_id'], 'uq_a006_student_career__1');
            $table->index(['student_id', 'valid_from', 'valid_until'], 'ix_a006_student_career__0');
            $table->foreign('student_id', 'fk_a006_student_career__student_id')->references('id')->on('students')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('career_id', 'fk_a006_student_career__career_id')->references('id')->on('careers')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('recorded_by', 'fk_a006_student_career__recorded_by')->references('id')->on('users')->restrictOnUpdate()->restrictOnDelete();
        });
        DB::statement('ALTER TABLE `student_career_records` ADD CONSTRAINT `ck_a006_student_career_records_0` CHECK (valid_until IS NULL OR valid_until > valid_from)');
        Schema::create('projects', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('academic_period_id');
            $table->unsignedBigInteger('career_id')->nullable();
            $table->string('code', 50);
            $table->string('name', 255);
            $table->string('status', 20)->default('active');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['academic_period_id', 'code'], 'uq_a006_projects_0');
            $table->unique(['id', 'academic_period_id'], 'uq_a006_projects_1');
            $table->index(['career_id', 'status'], 'ix_a006_projects_0');
            $table->foreign('academic_period_id', 'fk_a006_projects_academic_period_id')->references('id')->on('academic_periods')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('career_id', 'fk_a006_projects_career_id')->references('id')->on('careers')->restrictOnUpdate()->restrictOnDelete();
        });
        DB::statement("ALTER TABLE `projects` ADD CONSTRAINT `ck_a006_projects_0` CHECK (status IN ('active','inactive'))");
        Schema::create('participations', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('academic_period_id');
            $table->unsignedBigInteger('career_id');
            $table->unsignedBigInteger('student_career_record_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('registered_by');
            $table->dateTime('registered_at', 6);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['student_id', 'academic_period_id'], 'uq_a006_participations_0');
            $table->unique(['id', 'academic_period_id'], 'uq_a006_participations_1');
            $table->unique(['id', 'student_id'], 'uq_a006_participations_2');
            $table->index(['academic_period_id', 'student_id'], 'ix_a006_participations_0');
            $table->index(['academic_period_id', 'career_id', 'status'], 'ix_a006_participations_1');
            $table->index(['project_id'], 'ix_a006_participations_2');
            $table->foreign('student_id', 'fk_a006_participations_student_id')->references('id')->on('students')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('academic_period_id', 'fk_a006_participations_academic_period_id')->references('id')->on('academic_periods')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('career_id', 'fk_a006_participations_career_id')->references('id')->on('careers')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('student_career_record_id', 'fk_a006_participations_student_career_record_')->references('id')->on('student_career_records')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('project_id', 'fk_a006_participations_project_id')->references('id')->on('projects')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('registered_by', 'fk_a006_participations_registered_by')->references('id')->on('users')->restrictOnUpdate()->restrictOnDelete();
        });
        DB::statement("ALTER TABLE `participations` ADD CONSTRAINT `ck_a006_participations_0` CHECK (status IN ('active','completed','withdrawn'))");
        Schema::create('private_files', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->string('disk', 40)->default('local');
            $table->string('storage_key', 255)->collation('utf8mb4_bin');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->collation('utf8mb4_bin');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('status', 20)->default('staged');
            $table->dateTime('purged_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->unique(['disk', 'storage_key'], 'uq_a006_private_files_0');
            $table->index(['status', 'created_at'], 'ix_a006_private_files_0');
            $table->index(['sha256'], 'ix_a006_private_files_1');
            $table->foreign('uploaded_by', 'fk_a006_private_files_uploaded_by')->references('id')->on('users')->restrictOnUpdate()->restrictOnDelete();
        });
        DB::statement('ALTER TABLE `private_files` ADD CONSTRAINT `ck_a006_private_files_0` CHECK (size_bytes > 0)');
        DB::statement("ALTER TABLE `private_files` ADD CONSTRAINT `ck_a006_private_files_1` CHECK (status IN ('staged','ready','quarantined','purged'))");
        Schema::create('student_imports', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('academic_period_id');
            $table->unsignedBigInteger('career_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('source_file_id');
            $table->unsignedBigInteger('requested_by');
            $table->char('idempotency_key', 36)->collation('utf8mb4_bin');
            $table->string('status', 20)->default('pending');
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->string('error_summary', 1000)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['idempotency_key'], 'uq_a006_student_imports_0');
            $table->index(['academic_period_id', 'status', 'created_at'], 'ix_a006_student_imports_0');
            $table->foreign('academic_period_id', 'fk_a006_student_imports_academic_period_id')->references('id')->on('academic_periods')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('career_id', 'fk_a006_student_imports_career_id')->references('id')->on('careers')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('project_id', 'fk_a006_student_imports_project_id')->references('id')->on('projects')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('source_file_id', 'fk_a006_student_imports_source_file_id')->references('id')->on('private_files')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('requested_by', 'fk_a006_student_imports_requested_by')->references('id')->on('users')->restrictOnUpdate()->restrictOnDelete();
        });
        DB::statement("ALTER TABLE `student_imports` ADD CONSTRAINT `ck_a006_student_imports_0` CHECK (status IN ('pending','validating','processing','completed','failed'))");
        Schema::create('student_import_rows', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('student_import_id');
            $table->unsignedInteger('row_number');
            $table->char('row_fingerprint', 64)->collation('utf8mb4_bin');
            $table->unsignedBigInteger('student_id')->nullable();
            $table->unsignedBigInteger('participation_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('errors')->nullable();
            $table->dateTime('processed_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->unique(['student_import_id', 'row_number'], 'uq_a006_student_import__0');
            $table->index(['student_import_id', 'status'], 'ix_a006_student_import__0');
            $table->foreign('student_import_id', 'fk_a006_student_import__student_import_id')->references('id')->on('student_imports')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('student_id', 'fk_a006_student_import__student_id')->references('id')->on('students')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign('participation_id', 'fk_a006_student_import__participation_id')->references('id')->on('participations')->restrictOnUpdate()->restrictOnDelete();
        });
        DB::statement('ALTER TABLE `student_import_rows` ADD CONSTRAINT `ck_a006_student_import_rows_0` CHECK (`row_number` > 0)');
        DB::statement("ALTER TABLE `student_import_rows` ADD CONSTRAINT `ck_a006_student_import_rows_1` CHECK (status IN ('pending','valid','created','reused','error'))");
        Schema::table('participations', function (Blueprint $table) {
            $table->foreign(['student_career_record_id', 'student_id', 'career_id'], 'fk_a006_participation_career')->references(['id', 'student_id', 'career_id'])->on('student_career_records')->restrictOnUpdate()->restrictOnDelete();
            $table->foreign(['project_id', 'academic_period_id'], 'fk_a006_participation_project')->references(['id', 'academic_period_id'])->on('projects')->restrictOnUpdate()->restrictOnDelete();
        });
        Schema::table('student_imports', function (Blueprint $table) {
            $table->foreign(['project_id', 'academic_period_id'], 'fk_a006_import_project')->references(['id', 'academic_period_id'])->on('projects')->restrictOnUpdate()->restrictOnDelete();
        });
        // Complete the previously deferred relationship; existing invalid references must stop migration.
        Schema::table('audit_events', function (Blueprint $table) {
            $table->foreign('academic_period_id', 'fk_audit_events_period')->references('id')->on('academic_periods')->restrictOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Only for an explicitly authorized rollback: these drops would remove module data.
        Schema::table('audit_events', fn (Blueprint $table) => $table->dropForeign('fk_audit_events_period'));
        Schema::dropIfExists('student_import_rows');
        Schema::dropIfExists('student_imports');
        Schema::dropIfExists('private_files');
        Schema::dropIfExists('participations');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('student_career_records');
        Schema::dropIfExists('students');
        Schema::dropIfExists('teachers');
        Schema::dropIfExists('academic_periods');
        Schema::dropIfExists('careers');
    }
};
