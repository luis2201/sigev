<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityEvidence;
use App\Models\AttendanceDay;
use App\Models\AttendanceIncident;
use App\Models\AttendanceReview;
use App\Models\Career;
use App\Models\Participation;
use App\Models\ParticipationConfiguration;
use App\Models\PlacementConfiguration;
use App\Models\Project;
use App\Models\Role;
use App\Models\ScheduleInterval;
use App\Models\Student;
use App\Models\StudentCareerRecord;
use App\Models\Teacher;
use App\Models\TutorAssignment;
use App\Models\User;
use App\Services\AttendanceCalculator;
use App\Services\AttendanceLedger;
use App\Services\PlacementManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AttendanceModuleTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        $name = config('database.connections.mysql.database');
        if (getenv('SIGEV_TEST_DB_AUTHORIZED') !== '1' || config('database.default') !== 'mysql' || ! is_string($name) || ! str_ends_with($name, '_test') || $name === 'sigev') {
            $this->markTestSkipped('Requiere MariaDB aislada *_test y autorización explícita.');
        }
        DB::beginTransaction();
        $this->transactionStarted = true;
        Storage::fake('local');
        $this->at('07:30:00');
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function at(string $time, string $date = '2026-11-16'): void
    {
        $this->travelTo(CarbonImmutable::parse($date.' '.$time, 'America/Guayaquil'));
    }

    private function account(string $code): User
    {
        foreach (Role::LABELS as $value => $label) {
            Role::firstOrCreate(['code' => $value], ['name' => $label]);
        }
        $user = User::factory()->create();
        DB::table('role_user')->insert(['role_id' => Role::where('code', $code)->value('id'), 'user_id' => $user->id, 'assigned_by' => $user->id, 'assigned_at' => now('UTC'), 'created_at' => now('UTC')]);

        return $user;
    }

    private function context(bool $multiple = false): array
    {
        $coordinator = $this->account('coordinator');
        $studentUser = $this->account('student');
        $tutorUser = $this->account('tutor');
        $teacher = Teacher::create(['user_id' => $tutorUser->id, 'status' => 'active', 'created_by' => $coordinator->id]);
        $project = Project::factory()->create();
        $career = Career::create(['code' => 'ENF', 'name' => 'Enfermería', 'status' => 'active']);
        $student = Student::create(['user_id' => $studentUser->id, 'status' => 'active']);
        $record = StudentCareerRecord::create(['student_id' => $student->id, 'career_id' => $career->id, 'valid_from' => '2026-01-01', 'recorded_by' => $coordinator->id, 'reason' => 'Ingreso']);
        $p = Participation::create(['student_id' => $student->id, 'academic_period_id' => $project->academic_period_id, 'career_id' => $career->id, 'student_career_record_id' => $record->id, 'project_id' => $project->id, 'status' => 'active', 'registered_by' => $coordinator->id, 'registered_at' => now('UTC')]);
        $config = PlacementConfiguration::factory()->create(['project_id' => $project->id, 'academic_period_id' => $project->academic_period_id, 'created_by' => $coordinator->id, 'valid_from' => now('UTC')->subDay(), 'practice_starts_on' => '2026-11-01', 'practice_ends_on' => '2026-12-31']);
        ParticipationConfiguration::factory()->create(['participation_id' => $p->id, 'placement_configuration_id' => $config->id, 'academic_period_id' => $p->academic_period_id, 'assigned_by' => $coordinator->id, 'valid_from' => now('UTC')->subDay()]);
        TutorAssignment::factory()->create(['participation_id' => $p->id, 'teacher_id' => $teacher->id, 'assigned_by' => $coordinator->id, 'valid_from' => now('UTC')->subDay()]);
        ScheduleInterval::factory()->create(['placement_configuration_id' => $config->id, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00', 'valid_from' => '2026-11-01']);
        if ($multiple) {
            ScheduleInterval::factory()->create(['placement_configuration_id' => $config->id, 'weekday' => 1, 'starts_at' => '13:00', 'ends_at' => '16:00', 'valid_from' => '2026-11-01']);
        }

        return [$studentUser, $tutorUser, $p, $coordinator];
    }

    private function enter(User $user, Participation $p, ?string $key = null): AttendanceDay
    {
        $this->actingAs($user)->post(route('attendance.enter', $p), ['request_key' => $key ?? (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();

        return AttendanceDay::where('participation_id', $p->id)->latest('id')->firstOrFail();
    }

    private function photo(User $user, AttendanceDay $day): void
    {
        $this->actingAs($user)->post(route('attendance.activities.store', $day), ['description' => 'Actividad comunitaria documentada', 'photo' => UploadedFile::fake()->image('actividad.jpg', 60, 60)])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function leave(User $user, Participation $p, ?string $key = null): void
    {
        $this->actingAs($user)->post(route('attendance.leave', $p), ['request_key' => $key ?? (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function completed(): array
    {
        [$student, $tutor, $p, $coordinator] = $this->context();
        $this->at('08:00:00');
        $day = $this->enter($student, $p);
        $this->at('09:00:00');
        $this->photo($student, $day);
        $this->at('12:00:00');
        $this->leave($student, $p);

        return [$student, $tutor, $p, $day->fresh(), $coordinator];
    }

    private function decision(AttendanceDay $day, string $decision = 'ordinary'): array
    {
        return ['request_key' => (string) Str::uuid(), 'revision_number' => $day->fresh()->revision_number, 'decision' => $decision, 'justification' => $decision === 'ordinary' ? null : 'Decisión docente debidamente justificada'];
    }

    public function test_server_marks_entry_and_repeated_requests_do_not_duplicate_day_or_interval(): void
    {
        [$student,, $p] = $this->context();
        $this->at('08:10:00');
        $key = (string) Str::uuid();
        $day = $this->enter($student, $p, $key);
        $this->enter($student, $p, $key);
        $this->enter($student, $p);
        $this->assertDatabaseCount('attendance_days', 1);
        $this->assertDatabaseCount('attendance_intervals', 1);
        $this->assertSame('2026-11-16', $day->local_date->toDateString());
        $this->assertSame('2026-11-16 13:10:00', $day->intervals->first()->actual_entry_at->format('Y-m-d H:i:s'));
        $this->assertNull($day->intervals->first()->computable_minutes);
        $this->assertDatabaseHas('audit_events', ['action' => 'attendance.entry', 'actor_id' => $student->id]);
    }

    public function test_real_exit_is_recorded_and_no_time_is_credited_without_review(): void
    {
        [$student,, $p, $day] = $this->completed();
        $interval = $day->intervals->first();
        $this->assertSame('manual_closed', $interval->mark_status);
        $this->assertSame('240.000000', $interval->computable_minutes);
        $this->assertSame('pending', $day->status);
        $this->assertSame(0, app(AttendanceLedger::class)->progress($p)['credited_units']);
        $this->assertSame('17:00:00', $interval->actual_exit_at->format('H:i:s'));
    }

    public function test_exit_without_entry_and_manual_timestamp_inputs_are_rejected(): void
    {
        [$student,, $p] = $this->context();
        $this->actingAs($student)->post(route('attendance.leave', $p), ['request_key' => (string) Str::uuid()])->assertSessionHasErrors('attendance');
        $this->post(route('attendance.enter', $p), ['request_key' => (string) Str::uuid(), 'local_date' => '2026-11-01', 'actual_entry_at' => '2026-11-01 08:00:00'])->assertSessionHasErrors(['local_date', 'actual_entry_at']);
        $this->assertDatabaseCount('attendance_days', 0);
    }

    public function test_repeated_exit_and_replayed_entry_after_exit_do_not_create_new_marks(): void
    {
        [$student,, $p] = $this->context();
        $entryKey = (string) Str::uuid();
        $exitKey = (string) Str::uuid();
        $this->at('08:00:00');
        $day = $this->enter($student, $p, $entryKey);
        $this->at('09:00:00');
        $this->leave($student, $p, $exitKey);
        $this->at('10:00:00');
        $this->leave($student, $p, $exitKey);
        $this->enter($student, $p, $entryKey);
        $this->assertDatabaseCount('attendance_intervals', 1);
        $this->assertSame('14:00:00', $day->intervals->first()->actual_exit_at->format('H:i:s'));
    }

    public static function markTimes(): array
    {
        return [
            'early' => ['07:30:00', '12:00:00', '240.000000', 1800],
            'late' => ['09:00:00', '12:00:00', '180.000000', 0],
            'early exit' => ['08:00:00', '11:00:00', '180.000000', 0],
            'late exit' => ['08:00:00', '12:30:00', '240.000000', 1800],
            'seconds precision' => ['08:00:00', '08:01:01', '1.016667', 0],
        ];
    }

    #[DataProvider('markTimes')]
    public function test_intersection_of_real_marks_and_authorized_window_is_correct(string $entry, string $exit, string $minutes, int $outside): void
    {
        [$student,, $p] = $this->context();
        $this->at($entry);
        $day = $this->enter($student, $p);
        $this->at($exit);
        $this->leave($student, $p);
        $this->assertSame($minutes, $day->intervals->first()->computable_minutes);
        $this->assertSame($outside, app(AttendanceCalculator::class)->totals($day->intervals)['outside_seconds']);
    }

    public function test_multiple_segments_reuse_one_day_and_do_not_credit_break(): void
    {
        [$student, $tutor, $p] = $this->context(true);
        $this->at('08:00:00');
        $day = $this->enter($student, $p);
        $this->at('09:00:00');
        $this->photo($student, $day);
        $this->at('12:00:00');
        $this->leave($student, $p);
        $this->assertSame('in_progress', $day->fresh()->status);
        $this->actingAs($tutor)->post(route('attendance.review', $day), $this->decision($day))->assertSessionHasErrors('attendance');
        $this->at('12:30:00');
        $this->enter($student, $p);
        $this->at('14:00:00');
        $this->photo($student, $day);
        $this->at('16:00:00');
        $this->leave($student, $p);
        $this->assertDatabaseCount('attendance_days', 1);
        $this->assertDatabaseCount('attendance_intervals', 2);
        $totals = app(AttendanceCalculator::class)->totals($day->fresh()->intervals);
        $this->assertSame(7 * 3600, $totals['eligible_seconds']);
        $this->assertSame(1800, $totals['outside_seconds']);
        $this->actingAs($tutor)->post(route('attendance.review', $day), $this->decision($day))->assertSessionHasNoErrors();
        $this->assertSame(420000000, app(AttendanceLedger::class)->progress($p)['credited_units']);
    }

    public function test_closed_period_missing_assignment_and_disabled_tutor_block_entry(): void
    {
        [$student,, $p] = $this->context();
        $this->actingAs($student);
        $p->period->update(['status' => 'closed', 'closed_at' => now('UTC'), 'closed_by' => $student->id]);
        $this->post(route('attendance.enter', $p), ['request_key' => (string) Str::uuid()])->assertSessionHasErrors('attendance');
        $p->period->update(['status' => 'active', 'closed_at' => null, 'closed_by' => null]);
        $p->update(['project_id' => null]);
        $this->post(route('attendance.enter', $p), ['request_key' => (string) Str::uuid()])->assertSessionHasErrors('attendance');
        $p->update(['project_id' => $p->currentConfiguration->configuration->project_id]);
        $p->currentTutor->teacher->update(['status' => 'inactive']);
        $this->post(route('attendance.enter', $p), ['request_key' => (string) Str::uuid()])->assertSessionHasErrors('attendance');
        $this->assertDatabaseCount('attendance_days', 0);
    }

    public function test_no_schedule_for_today_and_late_entry_after_last_segment_are_not_enabled(): void
    {
        [$student,, $p] = $this->context();
        $this->at('13:00:00');
        $this->actingAs($student)->post(route('attendance.enter', $p), ['request_key' => (string) Str::uuid()])->assertSessionHasErrors('attendance');
        $this->at('08:00:00', '2026-11-17');
        $this->post(route('attendance.enter', $p), ['request_key' => (string) Str::uuid()])->assertSessionHasErrors('attendance');
        $this->get(route('attendance.index'))->assertOk()->assertSee('No tiene un horario de vinculación autorizado para hoy.');
        $this->assertDatabaseCount('attendance_days', 0);
    }

    public function test_activity_and_private_normalized_photo_are_created_consistently(): void
    {
        [$student, $tutor, $p] = $this->context();
        $this->at('08:00:00');
        $day = $this->enter($student, $p);
        $this->at('09:00:00');
        $this->photo($student, $day);
        $activity = Activity::with('evidence.file')->firstOrFail();
        $this->assertSame('complete', $activity->status);
        $this->assertSame('image/jpeg', $activity->evidence->file->mime_type);
        Storage::disk('local')->assertExists($activity->evidence->file->storage_key);
        $this->get(route('attendance.photo', $activity->evidence))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('attendance.activities.show', $activity))->assertOk()->assertSee('Actividad comunitaria documentada');
        $this->actingAs($tutor)->get(route('attendance.photo', $activity->evidence))->assertOk();
    }

    public function test_photo_is_mandatory_and_invalid_or_disguised_files_do_not_create_activity(): void
    {
        [$student,, $p] = $this->context();
        $this->at('08:00:00');
        $day = $this->enter($student, $p);
        $this->actingAs($student)->post(route('attendance.activities.store', $day), ['description' => 'Descripción'])->assertSessionHasErrors('photo');
        $this->post(route('attendance.activities.store', $day), ['description' => 'Descripción', 'photo' => UploadedFile::fake()->createWithContent('foto.jpg', '<?php echo 1;')])->assertSessionHasErrors('photo');
        $this->post(route('attendance.activities.store', $day), ['description' => 'Descripción', 'photo' => UploadedFile::fake()->image('foto.php', 10, 10)])->assertSessionHasErrors('photo');
        $this->assertDatabaseCount('activities', 0);
        $this->assertDatabaseCount('activity_evidences', 0);
    }

    public function test_oversized_photo_is_rejected_and_storage_failure_leaves_no_activity_or_evidence(): void
    {
        [$student,, $p] = $this->context();
        $this->at('08:00:00');
        $day = $this->enter($student, $p);
        config(['attendance.photo_max_kb' => 1]);
        $this->actingAs($student)->post(route('attendance.activities.store', $day), ['description' => 'Descripción', 'photo' => UploadedFile::fake()->image('foto.jpg', 100, 100)->size(2)])->assertSessionHasErrors('photo');
        config(['attendance.photo_max_kb' => 5120]);
        Storage::shouldReceive('disk')->with('local')->andReturnSelf();
        Storage::shouldReceive('put')->once()->andReturnFalse();
        Storage::shouldReceive('delete')->once()->andReturnTrue();
        $this->post(route('attendance.activities.store', $day), ['description' => 'Descripción', 'photo' => UploadedFile::fake()->image('foto.jpg', 10, 10)])->assertSessionHasErrors('photo');
        $this->assertDatabaseCount('activities', 0);
        $this->assertDatabaseCount('activity_evidences', 0);
    }

    public function test_other_students_cannot_read_photos_or_write_activities_and_past_records_are_blocked(): void
    {
        [$student,, $p] = $this->context();
        $this->at('08:00:00');
        $day = $this->enter($student, $p);
        $this->photo($student, $day);
        $stranger = $this->account('student');
        $this->actingAs($stranger)->get(route('attendance.photo', ActivityEvidence::firstOrFail()))->assertForbidden();
        $this->post(route('attendance.activities.store', $day), ['description' => 'Ajena', 'photo' => UploadedFile::fake()->image('foto.jpg')])->assertForbidden();
        $this->at('09:00:00', '2026-11-17');
        $this->actingAs($student)->post(route('attendance.activities.store', $day), ['description' => 'Pasada', 'photo' => UploadedFile::fake()->image('foto.jpg')])->assertSessionHasErrors('attendance');
        $this->assertDatabaseCount('activities', 1);
    }

    public function test_auto_close_uses_authorized_cutoff_preserves_real_entry_and_is_idempotent(): void
    {
        [$student,, $p] = $this->context();
        $this->at('08:10:00');
        $day = $this->enter($student, $p);
        $this->at('18:00:00');
        $this->artisan('sigev:close-attendance')->assertSuccessful();
        $this->artisan('sigev:close-attendance')->assertSuccessful();
        $interval = $day->fresh()->intervals->first();
        $this->assertSame('provisional_closed', $interval->mark_status);
        $this->assertNull($interval->actual_exit_at);
        $this->assertSame('17:00:00', $interval->provisional_closed_at->format('H:i:s'));
        $this->assertSame('13:10:00', $interval->actual_entry_at->format('H:i:s'));
        $this->assertSame('230.000000', $interval->computable_minutes);
        $this->assertSame('observed', $day->fresh()->status);
        $this->assertSame(1, AttendanceIncident::where('kind', 'missing_exit')->count());
        $this->assertSame(1, AttendanceIncident::where('kind', 'no_activities')->count());
        $this->assertSame(0, app(AttendanceLedger::class)->progress($p)['credited_units']);
        $this->actingAs($student)->post(route('attendance.leave', $p), ['request_key' => (string) Str::uuid()])->assertSessionHasErrors('attendance');
        $this->assertNull($interval->fresh()->actual_exit_at);
    }

    public function test_auto_close_between_segments_does_not_finish_day_prematurely(): void
    {
        [$student,, $p] = $this->context(true);
        $this->at('08:00:00');
        $day = $this->enter($student, $p);
        $this->at('12:00:00');
        $this->artisan('sigev:close-attendance')->assertSuccessful();
        $this->assertSame('in_progress', $day->fresh()->status);
        $this->assertNull($day->fresh()->closed_at);
        $this->at('16:00:00');
        $this->artisan('sigev:close-attendance')->assertSuccessful();
        $this->assertSame('observed', $day->fresh()->status);
    }

    public function test_ordinary_approval_credits_once_and_repeated_queries_do_not_mutate_ledger(): void
    {
        [, $tutor, $p, $day] = $this->completed();
        $data = $this->decision($day);
        $this->actingAs($tutor)->post(route('attendance.review', $day), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('attendance.review', $day), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('attendance_reviews', 1);
        $this->assertSame('approved', $day->fresh()->status);
        $this->assertSame(240000000, app(AttendanceLedger::class)->progress($p)['credited_units']);
        $this->assertSame(240000000, app(AttendanceLedger::class)->progress($p)['credited_units']);
        $this->get(route('attendance.index'))->assertOk()->assertSee('Revisión de jornadas');
        $this->get(route('attendance.show', $day))->assertOk()->assertSee('Efectiva');
    }

    public function test_unfinished_day_and_open_interval_cannot_be_approved(): void
    {
        [$student, $tutor, $p] = $this->context();
        $this->at('08:00:00');
        $day = $this->enter($student, $p);
        $this->actingAs($tutor)->post(route('attendance.review', $day), $this->decision($day))->assertSessionHasErrors('attendance');
        $this->assertDatabaseCount('attendance_reviews', 0);
    }

    public function test_ordinary_approval_requires_photos_and_cannot_approve_missing_exit(): void
    {
        [$student, $tutor, $p] = $this->context();
        $this->at('08:00:00');
        $day = $this->enter($student, $p);
        $this->at('12:00:00');
        $this->leave($student, $p);
        $this->actingAs($tutor)->post(route('attendance.review', $day), $this->decision($day))->assertSessionHasErrors('attendance');
        $this->assertDatabaseCount('attendance_reviews', 0);
    }

    public function test_exceptional_approval_without_activities_requires_justification_and_verifiable_support(): void
    {
        [$student, $tutor, $p] = $this->context();
        $this->at('08:00:00');
        $day = $this->enter($student, $p);
        $this->at('12:00:00');
        $this->artisan('sigev:close-attendance')->assertSuccessful();
        $data = $this->decision($day, 'exceptional');
        $this->actingAs($tutor)->post(route('attendance.review', $day), $data)->assertSessionHasErrors(['circumstance', 'support_reference']);
        $data += ['circumstance' => 'Capacitación institucional autorizada sin fotografías', 'support_reference' => 'Acta institucional 2026-015'];
        $this->post(route('attendance.review', $day), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('exceptional', $day->fresh()->status);
        $this->assertSame(240000000, app(AttendanceLedger::class)->progress($p)['credited_units']);
        $this->assertDatabaseCount('activities', 0);
    }

    public function test_observation_and_rejection_keep_history_and_do_not_credit_hours(): void
    {
        [, $tutor, $p, $day] = $this->completed();
        $this->actingAs($tutor)->post(route('attendance.review', $day), $this->decision($day, 'observed'))->assertSessionHasNoErrors();
        $this->post(route('attendance.review', $day), $this->decision($day, 'rejected'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('attendance_reviews', 2);
        $this->assertSame('rejected', $day->fresh()->status);
        $this->assertSame(0, app(AttendanceLedger::class)->progress($p)['credited_units']);
    }

    public function test_reopening_withdraws_credited_hours_and_preserves_original_review(): void
    {
        [, $tutor, $p, $day] = $this->completed();
        $this->actingAs($tutor)->post(route('attendance.review', $day), $this->decision($day))->assertSessionHasNoErrors();
        $old = AttendanceReview::firstOrFail();
        $this->post(route('attendance.review', $day), $this->decision($day, 'reopened'))->assertSessionHasNoErrors();
        $this->assertSame(0, app(AttendanceLedger::class)->progress($p)['credited_units']);
        $this->assertSame('240.000000', $old->fresh()->approved_minutes);
        $this->assertDatabaseCount('attendance_reviews', 2);
        $this->post(route('attendance.review', $day), $this->decision($day))->assertSessionHasNoErrors();
        $this->assertSame(240000000, app(AttendanceLedger::class)->progress($p)['credited_units']);
        $this->assertDatabaseCount('attendance_reviews', 3);
    }

    public function test_unassigned_teacher_admin_coordinator_and_student_cannot_approve(): void
    {
        [, , $p, $day, $coordinator] = $this->completed();
        foreach ([$this->account('tutor'), $this->account('admin'), $coordinator, $p->student->user] as $user) {
            $this->actingAs($user)->post(route('attendance.review', $day), $this->decision($day))->assertForbidden();
        }
        $this->assertDatabaseCount('attendance_reviews', 0);
    }

    public function test_stale_review_and_manual_approved_minutes_are_rejected(): void
    {
        [, $tutor, $p, $day] = $this->completed();
        $stale = $this->decision($day);
        $this->actingAs($tutor)->post(route('attendance.review', $day), $this->decision($day, 'observed'))->assertSessionHasNoErrors();
        $this->post(route('attendance.review', $day), $stale)->assertSessionHasErrors('attendance');
        $this->post(route('attendance.review', $day), $this->decision($day) + ['approved_minutes' => 99999])->assertSessionHasErrors('approved_minutes');
        $this->assertDatabaseCount('attendance_reviews', 1);
        $this->assertSame(0, app(AttendanceLedger::class)->progress($p)['credited_units']);
    }

    public function test_tutor_reassignment_preserves_previous_authorship_and_moves_review_authority(): void
    {
        [, $oldTutor, $p, $day, $coordinator] = $this->completed();
        $this->actingAs($oldTutor)->post(route('attendance.review', $day), $this->decision($day, 'observed'))->assertSessionHasNoErrors();
        $newUser = $this->account('tutor');
        $teacher = Teacher::create(['user_id' => $newUser->id, 'status' => 'active', 'created_by' => $coordinator->id]);
        $p->currentTutor->update(['valid_until' => now('UTC')]);
        TutorAssignment::factory()->create(['participation_id' => $p->id, 'teacher_id' => $teacher->id, 'assigned_by' => $coordinator->id, 'valid_from' => now('UTC')]);
        $this->actingAs($oldTutor)->post(route('attendance.review', $day), $this->decision($day))->assertForbidden();
        $this->get(route('attendance.show', $day))->assertOk();
        $this->actingAs($newUser)->post(route('attendance.review', $day), $this->decision($day))->assertSessionHasNoErrors();
        $this->assertSame($oldTutor->id, AttendanceReview::orderBy('id')->firstOrFail()->reviewed_by);
        $this->assertSame($newUser->id, $day->fresh()->currentReview->reviewed_by);
    }

    public function test_approved_and_previous_day_activities_cannot_be_modified_and_history_is_immutable(): void
    {
        [$student, $tutor, $p, $day] = $this->completed();
        $this->actingAs($tutor)->post(route('attendance.review', $day), $this->decision($day))->assertSessionHasNoErrors();
        $this->actingAs($student)->post(route('attendance.activities.store', $day), ['description' => 'Cambio posterior', 'photo' => UploadedFile::fake()->image('foto.jpg')])->assertSessionHasErrors('attendance');
        $this->expectException(\LogicException::class);
        AttendanceReview::firstOrFail()->update(['approved_minutes' => 999]);
    }

    public function test_database_enforces_one_day_and_one_open_interval_for_participation(): void
    {
        [$student,, $p] = $this->context();
        $day = $this->enter($student, $p);
        $this->expectException(QueryException::class);
        AttendanceDay::create(['participation_id' => $p->id, 'local_date' => $day->local_date, 'status' => 'in_progress', 'revision_number' => 1]);
    }

    public function test_student_and_tutor_dashboards_render_without_fictitious_data_and_guests_redirect(): void
    {
        [$student, $tutor, $p] = $this->context();
        $this->actingAs($student)->get(route('attendance.index'))->assertOk()->assertSee('Marcar entrada')->assertSee('Mis jornadas');
        $day = $this->enter($student, $p);
        $this->get(route('attendance.show', $day))->assertOk()->assertSee('Fotografía obligatoria');
        $this->actingAs($tutor)->get(route('attendance.index'))->assertOk()->assertSee($student->name);
        $this->actingAs($this->account('tutor'))->get(route('attendance.index'))->assertOk()->assertDontSee($student->name);
        $this->app['auth']->forgetGuards();
        $this->get(route('attendance.index'))->assertRedirect(route('login'));
    }

    public function test_missing_or_tampered_evidence_prevents_ordinary_approval(): void
    {
        [, $tutor, $p, $day] = $this->completed();
        $file = ActivityEvidence::firstOrFail()->file;
        Storage::disk('local')->put($file->storage_key, 'altered');
        $this->actingAs($tutor)->get(route('attendance.photo', ActivityEvidence::firstOrFail()))->assertNotFound();
        $this->post(route('attendance.review', $day), $this->decision($day))->assertSessionHasErrors('attendance');
        Storage::disk('local')->delete($file->storage_key);
        $this->post(route('attendance.review', $day), $this->decision($day))->assertSessionHasErrors('attendance');
        $this->assertSame(0, app(AttendanceLedger::class)->progress($p)['credited_units']);
    }

    public function test_database_failure_removes_uploaded_photo_and_rolls_back_activity(): void
    {
        [$student,, $p] = $this->context();
        $day = $this->enter($student, $p);
        Event::listen('eloquent.creating: '.ActivityEvidence::class, function (): never {
            throw new \RuntimeException('test failure');
        });
        try {
            $this->actingAs($student)->post(route('attendance.activities.store', $day), ['description' => 'Actividad documentada', 'photo' => UploadedFile::fake()->image('foto.jpg')])->assertSessionHasErrors('photo');
            $this->assertDatabaseCount('activities', 0);
            $this->assertDatabaseCount('private_files', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        } finally {
            Event::forget('eloquent.creating: '.ActivityEvidence::class);
        }
    }

    public function test_historical_snapshots_survive_new_schedule_versions_and_period_totals_are_unique(): void
    {
        [$student, $tutor, $p, $day] = $this->completed();
        $this->actingAs($tutor)->post(route('attendance.review', $day), $this->decision($day))->assertSessionHasNoErrors();
        $config = $p->currentConfiguration->configuration;
        $this->at('08:00:00', '2026-11-23');
        ScheduleInterval::factory()->create(['placement_configuration_id' => $config->id, 'weekday' => 1, 'starts_at' => '17:00', 'ends_at' => '18:00', 'valid_from' => '2026-11-23']);
        $second = $this->enter($student, $p);
        $this->photo($student, $second);
        $this->at('18:00:00', '2026-11-23');
        $this->leave($student, $p);
        $this->actingAs($tutor)->post(route('attendance.review', $second), $this->decision($second))->assertSessionHasNoErrors();
        $this->assertSame('240.000000', $day->fresh()->currentReview->approved_minutes);
        $this->assertSame(480000000, app(AttendanceLedger::class)->progress($p)['credited_units']);
        $this->assertSame(480000000, app(AttendanceLedger::class)->forParticipations(collect([$p]))[$p->id]['credited_units']);
    }

    public function test_provisional_exit_with_photos_requires_exception_and_closed_period_blocks_review(): void
    {
        [$student, $tutor, $p] = $this->context();
        $day = $this->enter($student, $p);
        $this->at('09:00:00');
        $this->photo($student, $day);
        $this->at('12:00:00');
        $this->artisan('sigev:close-attendance')->assertSuccessful();
        $this->actingAs($tutor)->post(route('attendance.review', $day), $this->decision($day))->assertSessionHasErrors('attendance');
        $p->period->update(['status' => 'closed', 'closed_at' => now('UTC'), 'closed_by' => $tutor->id]);
        $this->post(route('attendance.review', $day), $this->decision($day, 'observed'))->assertSessionHasErrors('attendance');
        $this->assertDatabaseCount('attendance_reviews', 0);
    }

    public function test_effective_review_cannot_reference_a_different_day(): void
    {
        [, $tutor, $p, $day] = $this->completed();
        $this->actingAs($tutor)->post(route('attendance.review', $day), $this->decision($day))->assertSessionHasNoErrors();
        $other = AttendanceDay::factory()->create(['participation_id' => $p->id, 'local_date' => '2026-11-17']);
        $this->expectException(QueryException::class);
        $other->update(['current_review_id' => $day->fresh()->current_review_id]);
    }

    public function test_database_rejects_two_open_intervals_for_the_same_participation(): void
    {
        [$student,, $p] = $this->context();
        $day = $this->enter($student, $p);
        $attributes = $day->intervals->first()->getAttributes();
        unset($attributes['id'], $attributes['open_participation_id']);
        $attributes['entry_request_key'] = (string) Str::uuid();
        $this->expectException(QueryException::class);
        DB::table('attendance_intervals')->insert($attributes);
    }

    public function test_two_concurrent_reviews_credit_only_once(): void
    {
        $tables = ['attendance_events', 'attendance_incidents', 'activity_evidences', 'activities', 'attendance_reviews', 'attendance_intervals', 'attendance_days', 'schedule_intervals', 'participation_configurations', 'tutor_assignments', 'group_memberships', 'placement_configurations', 'placement_groups', 'institutional_tutors', 'institution_areas', 'host_institutions', 'audit_events', 'student_import_rows', 'student_imports', 'private_files', 'participations', 'student_career_records', 'students', 'teachers', 'projects', 'academic_periods', 'careers', 'role_user', 'roles', 'users'];
        $baseline = [];
        foreach ($tables as $table) {
            $baseline[$table] = DB::table($table)->max('id') ?? 0;
        }
        [$student, $tutor, $p] = $this->context();
        $day = $this->enter($student, $p);
        $this->at('12:00:00');
        $this->artisan('sigev:close-attendance')->assertSuccessful();
        $data = $this->decision($day, 'exceptional') + ['circumstance' => 'Capacitación institucional autorizada', 'support_reference' => 'Acta institucional 005'];
        DB::commit();
        $this->transactionStarted = false;
        $path = tempnam(sys_get_temp_dir(), 'sigev-attendance-concurrent-');
        file_put_contents($path, json_encode(['actor' => $tutor->id, 'day' => $day->id, 'data' => $data], JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        $code = <<<'CODE'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Date::setTestNow(Carbon\CarbonImmutable::parse("2026-11-16 12:00:00", "America/Guayaquil"));
$input = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$input["data"]["request_key"] = (string) Illuminate\Support\Str::uuid();
try {
    $app->make(App\Services\AttendanceManager::class)->review(App\Models\User::findOrFail($input["actor"]), App\Models\AttendanceDay::findOrFail($input["day"]), $input["data"]);
    echo "applied";
} catch (Illuminate\Validation\ValidationException $exception) {
    echo "conflict";
}
CODE;
        try {
            $first = new Process([PHP_BINARY, '-r', $code, $path], base_path());
            $second = new Process([PHP_BINARY, '-r', $code, $path], base_path());
            $first->start();
            $second->start();
            $first->wait();
            $second->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
            $results = [$first->getOutput(), $second->getOutput()];
            sort($results);
            $this->assertSame(['applied', 'conflict'], $results);
            $this->assertSame(1, AttendanceReview::where('attendance_day_id', $day->id)->count());
            $this->assertSame(240000000, app(AttendanceLedger::class)->progress($p)['credited_units']);
        } finally {
            unlink($path);
            DB::table('attendance_days')->where('id', '>', $baseline['attendance_days'])->update(['current_review_id' => null]);
            foreach ($tables as $table) {
                DB::table($table)->where('id', '>', $baseline[$table])->delete();
            }
        }
    }

    public function test_assignment_edit_is_blocked_with_open_attendance_and_history_survives_new_version(): void
    {
        [$student, $tutor, $p, $coordinator] = $this->context();
        $editor = app(PlacementManager::class)->editor($coordinator, $p);
        $data = $editor['defaults'] + ['scope' => 'individual', 'participation_id' => $p->id, 'academic_period_id' => $p->academic_period_id];
        $data['intervals'][0]['ends_at'] = '11:00';
        $data['replace_existing'] = true;
        $this->at('08:00:00');
        $day = $this->enter($student, $p);
        try {
            app(PlacementManager::class)->review($coordinator, $data, app(PlacementManager::class)->review($coordinator, $data)['fingerprint'], $editor['revision']);
            $this->fail('No debe editarse una configuración con asistencia abierta.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('intervals', $exception->errors());
        }
        $this->photo($student, $day);
        $this->at('12:00:00');
        $this->leave($student, $p);
        $preview = app(PlacementManager::class)->review($coordinator, $data);
        app(PlacementManager::class)->review($coordinator, $data, $preview['fingerprint'], $editor['revision']);
        $this->assertNotSame($day->intervals->first()->placement_configuration_id, $p->fresh()->currentConfiguration->placement_configuration_id);
        $this->assertSame('17:00:00', $day->fresh()->intervals->first()->authorized_end_at->format('H:i:s'));
        $this->actingAs($tutor)->post(route('attendance.review', $day), $this->decision($day))->assertSessionHasNoErrors();
        $this->assertSame('240.000000', $day->fresh()->currentReview->approved_minutes);
    }

    public function test_student_cannot_self_approve_even_with_tutor_role_and_assignment(): void
    {
        [$student,, $p, $day, $coordinator] = $this->completed();
        DB::table('role_user')->insert(['role_id' => Role::where('code', 'tutor')->value('id'), 'user_id' => $student->id, 'assigned_by' => $coordinator->id, 'assigned_at' => now('UTC'), 'created_at' => now('UTC')]);
        $teacher = Teacher::create(['user_id' => $student->id, 'status' => 'active', 'created_by' => $coordinator->id]);
        $p->currentTutor->update(['valid_until' => now('UTC')]);
        TutorAssignment::factory()->create(['participation_id' => $p->id, 'teacher_id' => $teacher->id, 'assigned_by' => $coordinator->id]);
        $this->actingAs($student->fresh())->post(route('attendance.review', $day), $this->decision($day))->assertForbidden();
        $this->assertDatabaseCount('attendance_reviews', 0);
    }

    public function test_student_can_consult_selected_participation_with_filters_and_credited_hours(): void
    {
        [$student, $tutor, $p, $day] = $this->completed();
        $this->actingAs($tutor)->post(route('attendance.review', $day), $this->decision($day))->assertSessionHasNoErrors();

        $this->actingAs($student)->get(route('attendance.index', [
            'participation_id' => $p->id,
            'academic_period_id' => $p->academic_period_id,
            'project_id' => $p->project_id,
            'date' => '2026-11-16',
            'status' => 'approved',
        ]))->assertOk()
            ->assertViewHas('selected', fn (Participation $selected) => $selected->id === $p->id)
            ->assertViewHas('credited', 240000000)
            ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->id === $day->id);
    }
}
