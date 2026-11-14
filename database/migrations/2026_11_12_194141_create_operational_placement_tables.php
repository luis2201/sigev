<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_institutions', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->string('code', 50);
            $table->string('name', 255);
            $table->string('address', 500);
            $table->string('province', 100);
            $table->string('canton', 100);
            $table->string('status', 20)->default('active');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->string('normalized_name', 255)->unique();
            $table->unique('code');
        });
        Schema::create('institution_areas', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('host_institution_id');
            $table->string('name', 150);
            $table->string('status', 20)->default('active');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['host_institution_id', 'name'], 'uq_o007_1_0');
            $table->unique(['id', 'host_institution_id'], 'uq_o007_1_1');
            $table->foreign('host_institution_id', 'fk_o007_1_host_institution_id')->references('id')->on('host_institutions')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('institutional_tutors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_institution_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_area_id')->nullable();
            $table->string('name', 200);
            $table->string('normalized_name', 200);
            $table->string('email', 254)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('status', 20)->default('active');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['host_institution_id', 'normalized_name']);
            $table->foreign(['institution_area_id', 'host_institution_id'], 'fk_o007_it_area')->references(['id', 'host_institution_id'])->on('institution_areas')->restrictOnDelete();
        });
        Schema::create('placement_groups', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('academic_period_id');
            $table->string('name', 150);
            $table->unsignedBigInteger('created_by');
            $table->string('status', 20)->default('active');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['academic_period_id', 'name'], 'uq_o007_2_0');
            $table->unique(['id', 'academic_period_id'], 'uq_o007_2_1');
            $table->foreign('academic_period_id', 'fk_o007_2_academic_period_id')->references('id')->on('academic_periods')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'fk_o007_2_created_by')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('group_memberships', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('placement_group_id');
            $table->unsignedBigInteger('participation_id');
            $table->unsignedBigInteger('academic_period_id');
            $table->dateTime('valid_from', 6);
            $table->dateTime('valid_until', 6)->nullable();
            $table->unsignedBigInteger('assigned_by');
            $table->string('reason', 1000);
            $table->dateTime('created_at', 6);
            $table->unsignedBigInteger('current_participation_id')->nullable()->storedAs('CASE WHEN valid_until IS NULL THEN participation_id ELSE NULL END');
            $table->unique(['placement_group_id', 'current_participation_id'], 'uq_o007_current_group_member');
            $table->unique(['placement_group_id', 'participation_id', 'valid_from'], 'uq_o007_3_0');
            $table->foreign('placement_group_id', 'fk_o007_3_placement_group_id')->references('id')->on('placement_groups')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('participation_id', 'fk_o007_3_participation_id')->references('id')->on('participations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('academic_period_id', 'fk_o007_3_academic_period_id')->references('id')->on('academic_periods')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('assigned_by', 'fk_o007_3_assigned_by')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('tutor_assignments', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('participation_id');
            $table->unsignedBigInteger('teacher_id');
            $table->dateTime('valid_from', 6);
            $table->dateTime('valid_until', 6)->nullable();
            $table->unsignedBigInteger('assigned_by');
            $table->string('reason', 1000);
            $table->dateTime('created_at', 6);
            $table->unsignedBigInteger('current_participation_id')->nullable()->storedAs('CASE WHEN valid_until IS NULL THEN participation_id ELSE NULL END')->unique();
            $table->unique(['participation_id', 'valid_from'], 'uq_o007_4_0');
            $table->unique(['id', 'participation_id'], 'uq_o007_4_1');
            $table->foreign('participation_id', 'fk_o007_4_participation_id')->references('id')->on('participations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('teacher_id', 'fk_o007_4_teacher_id')->references('id')->on('teachers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('assigned_by', 'fk_o007_4_assigned_by')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('placement_configurations', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('academic_period_id');
            $table->unsignedBigInteger('placement_group_id')->nullable();
            $table->unsignedBigInteger('host_institution_id');
            $table->unsignedBigInteger('institution_area_id');
            $table->char('configuration_key', 36);
            $table->unsignedInteger('version_number');
            $table->string('institution_name_snapshot', 255);
            $table->string('address_snapshot', 500);
            $table->string('area_name_snapshot', 150);
            $table->string('institutional_tutor_name', 200);
            $table->date('practice_starts_on');
            $table->date('practice_ends_on');
            $table->unsignedInteger('required_minutes');
            $table->dateTime('valid_from', 6);
            $table->dateTime('valid_until', 6)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->string('reason', 1000);
            $table->dateTime('created_at', 6);
            $table->unsignedBigInteger('institutional_tutor_id');
            $table->unsignedBigInteger('project_id');
            $table->string('project_name_snapshot', 255);
            $table->unique(['configuration_key', 'version_number'], 'uq_o007_5_0');
            $table->unique(['id', 'academic_period_id'], 'uq_o007_5_1');
            $table->foreign('academic_period_id', 'fk_o007_5_academic_period_id')->references('id')->on('academic_periods')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('placement_group_id', 'fk_o007_5_placement_group_id')->references('id')->on('placement_groups')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('host_institution_id', 'fk_o007_5_host_institution_id')->references('id')->on('host_institutions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('institution_area_id', 'fk_o007_5_institution_area_id')->references('id')->on('institution_areas')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'fk_o007_5_created_by')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('participation_configurations', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('participation_id');
            $table->unsignedBigInteger('placement_configuration_id');
            $table->unsignedBigInteger('academic_period_id');
            $table->dateTime('valid_from', 6);
            $table->dateTime('valid_until', 6)->nullable();
            $table->unsignedBigInteger('assigned_by');
            $table->string('reason', 1000);
            $table->dateTime('created_at', 6);
            $table->unsignedBigInteger('current_participation_id')->nullable()->storedAs('CASE WHEN valid_until IS NULL THEN participation_id ELSE NULL END')->unique();
            $table->unique(['participation_id', 'valid_from'], 'uq_o007_6_0');
            $table->unique(['id', 'participation_id'], 'uq_o007_6_1');
            $table->foreign('participation_id', 'fk_o007_6_participation_id')->references('id')->on('participations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('placement_configuration_id', 'fk_o007_6_placement_configuration_id')->references('id')->on('placement_configurations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('academic_period_id', 'fk_o007_6_academic_period_id')->references('id')->on('academic_periods')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('assigned_by', 'fk_o007_6_assigned_by')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('schedule_intervals', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');
            $table->id();
            $table->unsignedBigInteger('placement_configuration_id');
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('active');
            $table->dateTime('created_at', 6);
            $table->unique(['placement_configuration_id', 'weekday', 'starts_at', 'valid_from'], 'uq_o007_7_0');
            $table->unique(['id', 'placement_configuration_id'], 'uq_o007_7_1');
            $table->foreign('placement_configuration_id', 'fk_o007_7_placement_configuration_id')->references('id')->on('placement_configurations')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement("ALTER TABLE `host_institutions` ADD CONSTRAINT `ck_o007_host_institutions_0` CHECK (status IN ('active','inactive'))");
        DB::statement("ALTER TABLE `institution_areas` ADD CONSTRAINT `ck_o007_institution_areas_0` CHECK (status IN ('active','inactive'))");
        DB::statement("ALTER TABLE `institutional_tutors` ADD CONSTRAINT `ck_o007_institutional_tutors_0` CHECK (status IN ('active','inactive'))");
        DB::statement("ALTER TABLE `placement_groups` ADD CONSTRAINT `ck_o007_placement_groups_0` CHECK (status IN ('active','inactive'))");
        DB::statement('ALTER TABLE `group_memberships` ADD CONSTRAINT `ck_o007_group_memberships_0` CHECK (valid_until IS NULL OR valid_until > valid_from)');
        DB::statement('ALTER TABLE `tutor_assignments` ADD CONSTRAINT `ck_o007_tutor_assignments_0` CHECK (valid_until IS NULL OR valid_until > valid_from)');
        DB::statement('ALTER TABLE `placement_configurations` ADD CONSTRAINT `ck_o007_placement_configurations_0` CHECK (required_minutes > 0 AND version_number > 0)');
        DB::statement('ALTER TABLE `placement_configurations` ADD CONSTRAINT `ck_o007_placement_configurations_1` CHECK (practice_ends_on >= practice_starts_on)');
        DB::statement('ALTER TABLE `placement_configurations` ADD CONSTRAINT `ck_o007_placement_configurations_2` CHECK (valid_until IS NULL OR valid_until > valid_from)');
        DB::statement('ALTER TABLE `participation_configurations` ADD CONSTRAINT `ck_o007_participation_configurations_0` CHECK (valid_until IS NULL OR valid_until > valid_from)');
        DB::statement("ALTER TABLE `schedule_intervals` ADD CONSTRAINT `ck_o007_schedule_intervals_0` CHECK (weekday BETWEEN 1 AND 7 AND starts_at >= '00:00:00' AND starts_at < ends_at AND ends_at < '24:00:00')");
        DB::statement('ALTER TABLE `schedule_intervals` ADD CONSTRAINT `ck_o007_schedule_intervals_1` CHECK (valid_until IS NULL OR valid_until > valid_from)');
        DB::statement("ALTER TABLE `schedule_intervals` ADD CONSTRAINT `ck_o007_schedule_intervals_2` CHECK (status IN ('active','inactive'))");
        Schema::table('participations', function (Blueprint $table) {
            $table->foreign(['project_id', 'academic_period_id'], 'fk_o007_composite_0')->references(['id', 'academic_period_id'])->on('projects')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('student_imports', function (Blueprint $table) {
            $table->foreign(['project_id', 'academic_period_id'], 'fk_o007_composite_1')->references(['id', 'academic_period_id'])->on('projects')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('group_memberships', function (Blueprint $table) {
            $table->foreign(['placement_group_id', 'academic_period_id'], 'fk_o007_composite_2')->references(['id', 'academic_period_id'])->on('placement_groups')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('group_memberships', function (Blueprint $table) {
            $table->foreign(['participation_id', 'academic_period_id'], 'fk_o007_composite_3')->references(['id', 'academic_period_id'])->on('participations')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('placement_configurations', function (Blueprint $table) {
            $table->foreign(['placement_group_id', 'academic_period_id'], 'fk_o007_composite_4')->references(['id', 'academic_period_id'])->on('placement_groups')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('placement_configurations', function (Blueprint $table) {
            $table->foreign(['institution_area_id', 'host_institution_id'], 'fk_o007_composite_5')->references(['id', 'host_institution_id'])->on('institution_areas')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('placement_configurations', function (Blueprint $table) {
            $table->foreign(['project_id', 'academic_period_id'], 'fk_o007_composite_6')->references(['id', 'academic_period_id'])->on('projects')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('participation_configurations', function (Blueprint $table) {
            $table->foreign(['participation_id', 'academic_period_id'], 'fk_o007_composite_7')->references(['id', 'academic_period_id'])->on('participations')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('participation_configurations', function (Blueprint $table) {
            $table->foreign(['placement_configuration_id', 'academic_period_id'], 'fk_o007_composite_8')->references(['id', 'academic_period_id'])->on('placement_configurations')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('placement_configurations', function (Blueprint $table) {
            $table->foreign('institutional_tutor_id')->references('id')->on('institutional_tutors')->restrictOnDelete();
        });
        Schema::table('tutor_assignments', function (Blueprint $table) {
            $table->index(['teacher_id', 'valid_until'], 'ix_o007_lookup_0');
        });
        Schema::table('group_memberships', function (Blueprint $table) {
            $table->index(['participation_id', 'valid_until'], 'ix_o007_lookup_1');
        });
        Schema::table('placement_configurations', function (Blueprint $table) {
            $table->index(['academic_period_id', 'valid_until'], 'ix_o007_lookup_2');
        });
        Schema::table('schedule_intervals', function (Blueprint $table) {
            $table->index(['placement_configuration_id', 'weekday', 'status'], 'ix_o007_lookup_3');
        });
    }

    public function down(): void
    {
        Schema::table('participations', function (Blueprint $table) {
            $table->dropForeign('fk_o007_composite_0');
        });
        Schema::table('student_imports', function (Blueprint $table) {
            $table->dropForeign('fk_o007_composite_1');
        });
        Schema::dropIfExists('schedule_intervals');
        Schema::dropIfExists('participation_configurations');
        Schema::dropIfExists('placement_configurations');
        Schema::dropIfExists('tutor_assignments');
        Schema::dropIfExists('group_memberships');
        Schema::dropIfExists('placement_groups');
        Schema::dropIfExists('institutional_tutors');
        Schema::dropIfExists('institution_areas');
        Schema::dropIfExists('host_institutions');
    }
};
