<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Career;
use App\Models\GroupMembership;
use App\Models\HostInstitution;
use App\Models\InstitutionalTutor;
use App\Models\InstitutionArea;
use App\Models\Participation;
use App\Models\ParticipationConfiguration;
use App\Models\PlacementConfiguration;
use App\Models\PlacementGroup;
use App\Models\Project;
use App\Models\Role;
use App\Models\ScheduleInterval;
use App\Models\Student;
use App\Models\StudentCareerRecord;
use App\Models\Teacher;
use App\Models\TutorAssignment;
use App\Models\User;
use App\Services\PlacementManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OperationalModuleTest extends TestCase
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
        $this->travelTo(now('UTC')->setDate(2026, 11, 12)->setTime(15, 0));
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
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

    private function context(): array
    {
        $coordinator = $this->account('coordinator');
        $project = Project::factory()->create();
        $career = Career::create(['code' => 'ENF', 'name' => 'Enfermería', 'status' => 'active']);
        $participation = $this->student($coordinator, $project->academic_period_id, $career);
        $institution = HostInstitution::factory()->create(['name' => 'Hospital de prueba']);
        $area = InstitutionArea::factory()->create(['host_institution_id' => $institution->id]);
        $it = InstitutionalTutor::factory()->create(['host_institution_id' => $institution->id, 'institution_area_id' => $area->id]);
        $teacherAccount = $this->account('tutor');
        $teacher = Teacher::create(['user_id' => $teacherAccount->id, 'status' => 'active', 'created_by' => $coordinator->id]);
        $data = ['academic_period_id' => $project->academic_period_id, 'participation_id' => $participation->id, 'project_id' => $project->id, 'host_institution_id' => $institution->id, 'institution_area_id' => $area->id, 'institutional_tutor_id' => $it->id, 'teacher_id' => $teacher->id, 'practice_starts_on' => '2026-11-01', 'practice_ends_on' => '2026-12-01', 'required_hours' => 160, 'reason' => 'Asignación inicial', 'intervals' => [['weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00', 'valid_from' => '2026-11-01', 'valid_until' => null], ['weekday' => 1, 'starts_at' => '13:00', 'ends_at' => '16:00', 'valid_from' => '2026-11-01', 'valid_until' => null]]];

        return [$coordinator, $participation, $data];
    }

    private function student(User $actor, int $periodId, Career $career): Participation
    {
        $user = $this->account('student');
        $student = Student::create(['user_id' => $user->id, 'status' => 'active']);
        $record = StudentCareerRecord::create(['student_id' => $student->id, 'career_id' => $career->id, 'valid_from' => '2026-01-01', 'recorded_by' => $actor->id, 'reason' => 'Ingreso']);

        return Participation::create(['student_id' => $student->id, 'academic_period_id' => $periodId, 'career_id' => $career->id, 'student_career_record_id' => $record->id, 'status' => 'active', 'registered_by' => $actor->id, 'registered_at' => now('UTC')]);
    }

    private function assign(User $actor, array $data): void
    {
        $this->actingAs($actor)->post(route('operational.assignments.preview'), $data)->assertOk()->assertSee('Estudiantes afectados');
        $nonce = session('placement_preview.nonce');
        $this->post(route('operational.assignments.confirm'), ['nonce' => $nonce, 'confirmation' => '1'])->assertRedirect(route('operational.assignments.index'));
    }

    public function test_individual_assignment_creates_snapshots_schedule_tutor_and_audit_without_duplicating_participation(): void
    {
        [$coordinator, $participation, $data] = $this->context();
        $this->assign($coordinator, $data);

        $this->assertDatabaseCount('participations', 1);
        $this->assertDatabaseHas('participations', ['id' => $participation->id, 'project_id' => $data['project_id']]);
        $this->assertDatabaseHas('placement_configurations', ['required_minutes' => 9600, 'version_number' => 1, 'institution_name_snapshot' => 'Hospital de prueba']);
        $this->assertDatabaseCount('schedule_intervals', 2);
        $this->get(route('operational.catalog.show', ['institutions', $data['host_institution_id']]))->assertOk()->assertSee('160,00')->assertSee('Configuraciones y proyectos relacionados');
        $this->assertDatabaseHas('tutor_assignments', ['teacher_id' => $data['teacher_id'], 'participation_id' => $participation->id, 'valid_until' => null]);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $coordinator->id, 'action' => 'placement.individual_applied', 'academic_period_id' => $data['academic_period_id']]);
    }

    public function test_catalog_forms_and_detail_pages_render_and_catalogs_can_be_created(): void
    {
        [$actor, $participation, $data] = $this->context();
        $this->actingAs($actor);
        $entries = [
            'projects' => ['name' => 'Proyecto comunitario', 'code' => 'PC-01', 'academic_period_id' => $data['academic_period_id']],
            'institutions' => ['name' => 'Centro comunitario', 'code' => 'CC-01', 'address' => 'Dirección', 'province' => 'Manabí', 'canton' => 'Portoviejo'],
            'areas' => ['name' => 'Servicio comunitario', 'host_institution_id' => $data['host_institution_id']],
            'institutional-tutors' => ['name' => 'Responsable institucional', 'host_institution_id' => $data['host_institution_id'], 'institution_area_id' => $data['institution_area_id']],
            'groups' => ['name' => 'Grupo comunitario', 'academic_period_id' => $data['academic_period_id']],
        ];
        foreach ($entries as $kind => $entry) {
            $this->get(route('operational.catalog.index', $kind))->assertOk();
            $this->get(route('operational.catalog.create', $kind))->assertOk();
            $response = $this->post(route('operational.catalog.store', $kind), $entry + ['status' => 'active']);
            $response->assertSessionHasNoErrors()->assertRedirect();
            $this->get($response->headers->get('Location'))->assertOk()->assertSee($entry['name']);
        }
        $this->get(route('operational.assignments.create', ['academic_period_id' => $data['academic_period_id']]))->assertOk();
        $this->assertDatabaseHas('projects', ['code' => 'PC-01', 'academic_period_id' => $data['academic_period_id']]);
        $this->assertDatabaseCount('users', 4);
    }

    public function test_normalized_institution_names_and_area_names_cannot_be_duplicated(): void
    {
        [$actor,, $data] = $this->context();
        $this->actingAs($actor)->post(route('operational.catalog.store', 'institutions'), ['name' => ' HOSPITAL  de PRUEBA. ', 'code' => 'OTHER', 'address' => 'Dirección', 'province' => 'Manabí', 'canton' => 'Portoviejo', 'status' => 'active'])->assertSessionHasErrors('name');
        $area = InstitutionArea::findOrFail($data['institution_area_id']);
        $this->post(route('operational.catalog.store', 'areas'), ['name' => $area->name, 'host_institution_id' => $data['host_institution_id'], 'status' => 'active'])->assertSessionHasErrors('name');
        $this->assertDatabaseCount('host_institutions', 1);
        $this->assertDatabaseCount('institution_areas', 1);
    }

    public function test_tutor_registry_rejects_area_from_another_institution_and_creates_no_account(): void
    {
        [$actor,, $data] = $this->context();
        $area = InstitutionArea::factory()->create();
        $count = User::count();
        $this->actingAs($actor)->post(route('operational.catalog.store', 'institutional-tutors'), ['name' => 'Responsable', 'host_institution_id' => $data['host_institution_id'], 'institution_area_id' => $area->id, 'status' => 'active'])->assertSessionHasErrors('institution_area_id');
        $this->assertSame($count, User::count());
    }

    public static function invalidConfigurations(): array
    {
        return [
            'horas cero' => ['required_hours', 0, 'required_hours'],
            'horas negativas' => ['required_hours', -1, 'required_hours'],
            'minuto incompleto' => ['required_hours', 1.001, 'required_hours'],
            'fin anterior' => ['practice_ends_on', '2026-10-01', 'practice_ends_on'],
            'fuera de período' => ['practice_ends_on', '2027-01-01', 'practice_starts_on'],
            'motivo vacío' => ['reason', '', 'reason'],
        ];
    }

    #[DataProvider('invalidConfigurations')]
    public function test_invalid_configuration_is_rejected_without_partial_changes(string $key, mixed $value, string $field): void
    {
        [$actor,, $data] = $this->context();
        $data[$key] = $value;
        $this->actingAs($actor)->post(route('operational.assignments.preview'), $data)->assertSessionHasErrors($field);
        $this->assertDatabaseCount('placement_configurations', 0);
        $this->assertDatabaseCount('tutor_assignments', 0);
    }

    public function test_project_area_and_institutional_tutor_must_match_context(): void
    {
        [$actor,, $data] = $this->context();
        $bad = $data;
        $bad['project_id'] = Project::factory()->create()->id;
        $this->actingAs($actor)->post(route('operational.assignments.preview'), $bad)->assertSessionHasErrors('project_id');
        $bad = $data;
        $bad['institution_area_id'] = InstitutionArea::factory()->create()->id;
        $this->post(route('operational.assignments.preview'), $bad)->assertSessionHasErrors('institution_area_id');
        $bad = $data;
        $bad['institutional_tutor_id'] = InstitutionalTutor::factory()->create()->id;
        $this->post(route('operational.assignments.preview'), $bad)->assertSessionHasErrors('institutional_tutor_id');
        $this->assertDatabaseCount('placement_configurations', 0);
    }

    public function test_inactive_or_revoked_teachers_cannot_be_assigned(): void
    {
        [$actor,, $data] = $this->context();
        $teacher = Teacher::findOrFail($data['teacher_id']);
        $teacher->update(['status' => 'inactive']);
        $this->actingAs($actor)->post(route('operational.assignments.preview'), $data)->assertSessionHasErrors('teacher_id');
        $teacher->update(['status' => 'active']);
        DB::table('role_user')->where('user_id', $teacher->user_id)->update(['revoked_at' => now('UTC')]);
        $this->post(route('operational.assignments.preview'), $data)->assertSessionHasErrors('teacher_id');
        $this->assertDatabaseCount('tutor_assignments', 0);
    }

    public function test_schedule_rejects_overlap_and_nonpositive_intervals_but_accepts_adjacent_intervals(): void
    {
        [$actor,, $data] = $this->context();
        $bad = $data;
        $bad['intervals'][1]['starts_at'] = '11:00';
        $this->actingAs($actor)->post(route('operational.assignments.preview'), $bad)->assertSessionHasErrors('intervals');
        $bad['intervals'][1]['starts_at'] = '16:00';
        $this->post(route('operational.assignments.preview'), $bad)->assertSessionHasErrors('intervals');
        $data['intervals'][1]['starts_at'] = '12:00';
        $this->assign($actor, $data);
        $this->assertDatabaseCount('schedule_intervals', 2);
    }

    public function test_schedule_validity_rejects_dates_outside_practice_and_allows_nonoverlapping_date_ranges(): void
    {
        [$actor,, $data] = $this->context();
        $data['intervals'][0]['valid_from'] = '2026-10-01';
        $this->actingAs($actor)->post(route('operational.assignments.preview'), $data)->assertSessionHasErrors('intervals');
        $data['intervals'][0]['valid_from'] = '2026-11-01';
        $data['intervals'][0]['valid_until'] = '2026-11-10';
        $data['intervals'][1]['starts_at'] = '08:00';
        $data['intervals'][1]['valid_from'] = '2026-11-11';
        $this->assign($actor, $data);
        $this->assertDatabaseCount('schedule_intervals', 2);
    }

    public function test_reassignment_versions_hours_schedule_project_and_teacher_with_history(): void
    {
        [$actor, $participation, $data] = $this->context();
        $this->assign($actor, $data);
        $first = PlacementConfiguration::firstOrFail();
        $oldTutor = TutorAssignment::firstOrFail();
        $account = $this->account('tutor');
        $teacher = Teacher::create(['user_id' => $account->id, 'status' => 'active', 'created_by' => $actor->id]);
        $data['teacher_id'] = $teacher->id;
        $data['replace_existing'] = true;
        $data['required_hours'] = 180;
        $data['intervals'][0]['starts_at'] = '09:00';
        $data['reason'] = 'Reasignación autorizada';
        $this->travel(1)->hours();
        $this->assign($actor, $data);
        $this->assertNotNull($first->fresh()->valid_until);
        $this->assertNotNull($oldTutor->fresh()->valid_until);
        $latest = PlacementConfiguration::orderByDesc('id')->firstOrFail();
        $this->assertSame($first->configuration_key, $latest->configuration_key);
        $this->assertSame(2, $latest->version_number);
        $this->assertSame(9600, $first->required_minutes);
        $this->assertSame(10800, $latest->required_minutes);
        $this->assertSame('08:00:00', $first->intervals->first()->starts_at);
        $this->assertSame('09:00:00', $latest->intervals->first()->starts_at);
        $this->assertSame($first->fresh()->valid_until->format('Y-m-d H:i:s.u'), $latest->valid_from->format('Y-m-d H:i:s.u'));
        $this->assertSame(1, TutorAssignment::whereNull('valid_until')->count());
        $this->assertDatabaseCount('participations', 1);
    }

    public function test_one_group_per_period_requires_withdrawal_before_transfer_and_preserves_history(): void
    {
        [$actor, $p, $data] = $this->context();
        $group = PlacementGroup::factory()->create(['academic_period_id' => $data['academic_period_id'], 'created_by' => $actor->id]);
        $other = PlacementGroup::factory()->create(['academic_period_id' => $data['academic_period_id'], 'created_by' => $actor->id]);
        $member = ['participation_id' => $p->id, 'reason' => 'Incorporación', 'action' => 'add'];
        $this->actingAs($actor)->post(route('operational.memberships.store', $group), $member)->assertRedirect();
        $this->post(route('operational.memberships.store', $group), $member)->assertSessionHasErrors('participation_id');
        $this->post(route('operational.memberships.store', $other), $member)->assertSessionHasErrors('participation_id');
        $member['action'] = 'remove';
        $this->post(route('operational.memberships.store', $group), $member)->assertRedirect();
        $member['action'] = 'add';
        $this->post(route('operational.memberships.store', $other), $member)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, GroupMembership::count());
        $this->assertSame(1, GroupMembership::whereNull('valid_until')->count());
        $this->get(route('operational.catalog.show', ['groups', $group->id]))->assertOk()
            ->assertViewHas('records', fn ($records) => $records->total() === 0)
            ->assertViewHas('memberships', fn ($memberships) => $memberships->total() === 1);
        $this->assertDatabaseCount('participations', 1);
    }

    public function test_group_assignment_detects_conflicts_then_replaces_all_members_only_after_explicit_authorization(): void
    {
        [$actor, $p, $data] = $this->context();
        $this->assign($actor, $data);
        $second = $this->student($actor, $p->academic_period_id, $p->career);
        $group = PlacementGroup::factory()->create(['academic_period_id' => $p->academic_period_id, 'created_by' => $actor->id]);
        foreach ([$p, $second] as $student) {
            $this->post(route('operational.memberships.store', $group), ['participation_id' => $student->id, 'action' => 'add', 'reason' => 'Incorporación'])->assertRedirect();
        }
        unset($data['participation_id']);
        $data['placement_group_id'] = $group->id;
        $this->post(route('operational.assignments.preview'), $data)->assertOk()->assertSee('Existen conflictos');
        $nonce = session('placement_preview.nonce');
        $this->post(route('operational.assignments.confirm'), ['nonce' => $nonce, 'confirmation' => '1'])->assertSessionHasErrors('replace_existing');
        $this->assertDatabaseCount('placement_configurations', 1);
        $data['replace_existing'] = true;
        $this->assign($actor, $data);
        $this->assertSame(2, ParticipationConfiguration::whereNull('valid_until')->count());
        $this->assertDatabaseCount('placement_configurations', 3);
        $this->assertDatabaseHas('audit_events', ['action' => 'placement.group_applied', 'entity_id' => $group->id]);
    }

    public function test_group_change_after_preview_and_repeated_confirmation_are_rejected(): void
    {
        [$actor, $p, $data] = $this->context();
        $this->assign($actor, $data);
        $nonce = '00000000-0000-4000-8000-000000000000';
        $this->post(route('operational.assignments.confirm'), ['nonce' => $nonce, 'confirmation' => '1'])->assertStatus(409);
        $this->assertDatabaseCount('placement_configurations', 1);
        $data['replace_existing'] = true;
        $this->post(route('operational.assignments.preview'), $data)->assertOk();
        $nonce = session('placement_preview.nonce');
        $p->update(['project_id' => null]);
        $this->post(route('operational.assignments.confirm'), ['nonce' => $nonce, 'confirmation' => '1'])->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('placement_configurations', 1);
    }

    public function test_closed_period_rejects_confirmation_membership_and_project_changes(): void
    {
        [$actor, $p, $data] = $this->context();
        $this->actingAs($actor)->post(route('operational.assignments.preview'), $data)->assertOk();
        $nonce = session('placement_preview.nonce');
        $group = PlacementGroup::factory()->create(['academic_period_id' => $p->academic_period_id, 'created_by' => $actor->id]);
        $p->period->update(['status' => 'closed', 'closed_at' => now('UTC'), 'closed_by' => $actor->id]);
        $this->post(route('operational.assignments.confirm'), ['nonce' => $nonce, 'confirmation' => '1'])->assertSessionHasErrors('academic_period_id');
        $this->post(route('operational.memberships.store', $group), ['participation_id' => $p->id, 'reason' => 'Ingreso', 'action' => 'add'])->assertSessionHasErrors('academic_period_id');
        $this->post(route('operational.catalog.store', 'projects'), ['academic_period_id' => $p->academic_period_id, 'code' => 'CLOSED', 'name' => 'Proyecto', 'status' => 'active'])->assertSessionHasErrors('academic_period_id');
        $this->assertDatabaseCount('placement_configurations', 0);
    }

    public function test_permissions_are_exclusive_to_coordinator_and_student_cannot_write(): void
    {
        [$actor, $p, $data] = $this->context();
        $this->assertTrue(Gate::forUser($actor)->allows('manage-placements'));
        foreach (['admin', 'tutor', 'student'] as $role) {
            $user = $this->account($role);
            $this->assertFalse(Gate::forUser($user)->allows('manage-placements'));
            $this->actingAs($user)->get(route('operational.assignments.create'))->assertForbidden();
            $this->post(route('operational.assignments.preview'), $data)->assertForbidden();
            $this->post(route('operational.catalog.store', 'institutions'), [])->assertForbidden();
        }
        $this->assertDatabaseCount('placement_configurations', 0);
    }

    public function test_student_and_teacher_can_read_only_their_authorized_students_and_history(): void
    {
        [$actor, $p, $data] = $this->context();
        $this->assign($actor, $data);
        $this->actingAs($p->student->user)->get(route('operational.mine'))->assertOk()->assertSee('Hospital de prueba');
        $this->get(route('operational.assignments.show', $p))->assertOk()->assertSee('160,00')->assertSee('Horario semanal');
        $stranger = $this->account('student');
        $this->actingAs($stranger)->get(route('operational.assignments.show', $p))->assertForbidden();
        $this->get(route('operational.mine'))->assertOk()->assertDontSee('Hospital de prueba');
        $teacher = Teacher::findOrFail($data['teacher_id']);
        $this->actingAs($teacher->user)->get(route('operational.students'))->assertOk()->assertSee($p->student->user->name);
        $this->get(route('operational.assignments.show', $p))->assertOk();
        $outsider = $this->account('tutor');
        $this->actingAs($outsider)->get(route('operational.students'))->assertOk()->assertDontSee($p->student->user->name);
        $this->get(route('operational.assignments.show', $p))->assertForbidden();
    }

    public function test_former_teacher_can_read_own_historical_configuration_but_not_new_conditions(): void
    {
        [$actor, $p, $data] = $this->context();
        $old = Teacher::findOrFail($data['teacher_id']);
        $this->assign($actor, $data);
        $new = $this->account('tutor');
        $teacher = Teacher::create(['user_id' => $new->id, 'status' => 'active', 'created_by' => $actor->id]);
        $this->travel(1)->hours();
        $data['teacher_id'] = $teacher->id;
        $data['replace_existing'] = true;
        $data['required_hours'] = 180;
        $data['reason'] = 'Condiciones nuevas privadas';
        $this->assign($actor, $data);
        $this->actingAs($old->user)->get(route('operational.students'))->assertOk()->assertDontSee($p->student->user->name);
        $this->get(route('operational.students', ['history' => 1]))->assertOk()->assertSee($p->student->user->name);
        $this->get(route('operational.assignments.show', $p))->assertOk()->assertSee('160,00')->assertDontSee('180,00')->assertDontSee('Condiciones nuevas privadas');
    }

    public function test_syncing_missing_teacher_profiles_is_idempotent_and_preserves_credentials_and_roles(): void
    {
        $account = $this->account('tutor');
        $password = $account->password;
        $this->artisan('sigev:sync-teacher-profiles')->assertSuccessful();
        $this->artisan('sigev:sync-teacher-profiles')->assertSuccessful();
        $this->assertDatabaseCount('teachers', 1);
        $this->assertSame($password, $account->fresh()->password);
        $this->assertTrue($account->fresh()->hasRole('tutor'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_database_rejects_two_current_tutor_assignments(): void
    {
        [$actor, $p, $data] = $this->context();
        $this->assign($actor, $data);
        $this->expectException(QueryException::class);
        TutorAssignment::create(['participation_id' => $p->id, 'teacher_id' => $data['teacher_id'], 'valid_from' => now('UTC')->addHour(), 'assigned_by' => $actor->id, 'reason' => 'Duplicado']);
    }

    public function test_database_rejects_configuration_from_another_period(): void
    {
        [$actor, $p, $data] = $this->context();
        $configuration = PlacementConfiguration::factory()->create();
        $this->expectException(QueryException::class);
        ParticipationConfiguration::create(['participation_id' => $p->id, 'academic_period_id' => $p->academic_period_id, 'placement_configuration_id' => $configuration->id, 'valid_from' => now('UTC'), 'assigned_by' => $actor->id, 'reason' => 'Inválida']);
    }

    public function test_historical_configuration_and_schedule_cannot_be_mutated(): void
    {
        [$actor,, $data] = $this->context();
        $this->assign($actor, $data);
        $this->expectException(\LogicException::class);
        PlacementConfiguration::firstOrFail()->update(['required_minutes' => 1]);
    }

    public function test_catalog_optimistic_revision_prevents_lost_updates(): void
    {
        [$actor,, $data] = $this->context();
        $project = Project::findOrFail($data['project_id']);
        $payload = ['name' => 'Nombre editado', 'code' => $project->code, 'academic_period_id' => $project->academic_period_id, 'status' => 'active', 'revision' => $project->getRawOriginal('updated_at')];
        $this->travel(1)->seconds();
        $this->actingAs($actor)->put(route('operational.catalog.update', ['projects', $project->id]), $payload)->assertRedirect();
        $payload['name'] = 'Actualización obsoleta';
        $this->put(route('operational.catalog.update', ['projects', $project->id]), $payload)->assertSessionHasErrors('revision');
        $this->assertSame('Nombre editado', $project->fresh()->name);
    }

    public function test_group_preview_becomes_stale_when_membership_changes(): void
    {
        [$actor, $p, $data] = $this->context();
        $group = PlacementGroup::factory()->create(['academic_period_id' => $p->academic_period_id, 'created_by' => $actor->id]);
        $second = $this->student($actor, $p->academic_period_id, $p->career);
        $this->actingAs($actor)->post(route('operational.memberships.store', $group), ['participation_id' => $p->id, 'action' => 'add', 'reason' => 'Ingreso'])->assertRedirect();
        unset($data['participation_id']);
        $data['placement_group_id'] = $group->id;
        $this->post(route('operational.assignments.preview'), $data)->assertOk();
        $nonce = session('placement_preview.nonce');
        $this->post(route('operational.memberships.store', $group), ['participation_id' => $second->id, 'action' => 'add', 'reason' => 'Nuevo ingreso'])->assertRedirect();
        $this->post(route('operational.assignments.confirm'), ['nonce' => $nonce, 'confirmation' => '1'])->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('placement_configurations', 0);
    }

    public function test_group_with_ineligible_student_fails_without_partial_assignments(): void
    {
        [$actor, $p, $data] = $this->context();
        $group = PlacementGroup::factory()->create(['academic_period_id' => $p->academic_period_id, 'created_by' => $actor->id]);
        $second = $this->student($actor, $p->academic_period_id, $p->career);
        foreach ([$p, $second] as $student) {
            $this->actingAs($actor)->post(route('operational.memberships.store', $group), ['participation_id' => $student->id, 'action' => 'add', 'reason' => 'Ingreso'])->assertRedirect();
        }
        $second->update(['status' => 'withdrawn']);
        unset($data['participation_id']);
        $data['placement_group_id'] = $group->id;
        $this->post(route('operational.assignments.preview'), $data)->assertSessionHasErrors('participation_id');
        $this->assertDatabaseCount('placement_configurations', 0);
        $this->assertDatabaseCount('tutor_assignments', 0);
    }

    public function test_membership_and_project_reject_incompatible_period_and_career(): void
    {
        [$actor, $p, $data] = $this->context();
        $group = PlacementGroup::factory()->create();
        $this->actingAs($actor)->post(route('operational.memberships.store', $group), ['participation_id' => $p->id, 'action' => 'add', 'reason' => 'Ingreso'])->assertSessionHasErrors('participation_id');
        $otherCareer = Career::create(['code' => 'OTRA', 'name' => 'Otra carrera', 'status' => 'active']);
        Project::findOrFail($data['project_id'])->update(['career_id' => $otherCareer->id]);
        $this->post(route('operational.assignments.preview'), $data)->assertSessionHasErrors('project_id');
        $this->assertDatabaseCount('group_memberships', 0);
    }

    public function test_confirmation_rechecks_teacher_role_and_expiration(): void
    {
        [$actor,, $data] = $this->context();
        $this->actingAs($actor)->post(route('operational.assignments.preview'), $data)->assertOk();
        $nonce = session('placement_preview.nonce');
        $teacher = Teacher::findOrFail($data['teacher_id']);
        DB::table('role_user')->where('user_id', $teacher->user_id)->update(['revoked_at' => now('UTC')]);
        $this->post(route('operational.assignments.confirm'), ['nonce' => $nonce, 'confirmation' => '1'])->assertSessionHasErrors('teacher_id');
        $this->travel(21)->minutes();
        $this->post(route('operational.assignments.confirm'), ['nonce' => $nonce, 'confirmation' => '1'])->assertStatus(409);
        $this->assertDatabaseCount('placement_configurations', 0);
    }

    public function test_renaming_catalog_does_not_change_historical_snapshots(): void
    {
        [$actor, $p, $data] = $this->context();
        $this->assign($actor, $data);
        HostInstitution::findOrFail($data['host_institution_id'])->update(['name' => 'Nombre nuevo']);
        $this->actingAs($p->student->user)->get(route('operational.assignments.show', $p))->assertOk()->assertSee('Hospital de prueba')->assertDontSee('Nombre nuevo');
    }

    public function test_historical_schedule_interval_cannot_be_changed(): void
    {
        [$actor,, $data] = $this->context();
        $this->assign($actor, $data);
        $this->expectException(\LogicException::class);
        ScheduleInterval::firstOrFail()->update(['starts_at' => '09:00']);
    }

    public function test_database_rejects_two_current_configurations(): void
    {
        [$actor, $p, $data] = $this->context();
        $this->assign($actor, $data);
        $this->expectException(QueryException::class);
        ParticipationConfiguration::create(['participation_id' => $p->id, 'placement_configuration_id' => PlacementConfiguration::firstOrFail()->id, 'academic_period_id' => $p->academic_period_id, 'valid_from' => now('UTC')->addHour(), 'assigned_by' => $actor->id, 'reason' => 'Duplicada']);
    }

    public function test_guests_must_sign_in_before_accessing_operational_data(): void
    {
        $this->get(route('operational.assignments.index'))->assertRedirect(route('login'));
        $this->get(route('operational.mine'))->assertRedirect(route('login'));
        $this->post(route('operational.assignments.confirm'), [])->assertRedirect(route('login'));
    }

    public function test_two_concurrent_confirmations_allow_only_one_writer_and_preserve_integrity(): void
    {
        $tables = ['schedule_intervals', 'participation_configurations', 'tutor_assignments', 'group_memberships', 'placement_configurations', 'placement_groups', 'institutional_tutors', 'institution_areas', 'host_institutions', 'audit_events', 'student_import_rows', 'student_imports', 'private_files', 'participations', 'student_career_records', 'students', 'teachers', 'projects', 'academic_periods', 'careers', 'role_user', 'roles', 'users'];
        $baseline = [];
        foreach ($tables as $table) {
            $baseline[$table] = DB::table($table)->max('id') ?? 0;
        }
        [$actor, $p, $data] = $this->context();
        $review = app(PlacementManager::class)->review($actor, $data);
        DB::commit();
        $this->transactionStarted = false;
        $path = tempnam(sys_get_temp_dir(), 'sigev-concurrent-');
        file_put_contents($path, json_encode(['actor' => $actor->id, 'data' => $data, 'fingerprint' => $review['fingerprint']], JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        $code = <<<'CODE'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$input = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
try {
    $app->make(App\Services\PlacementManager::class)->review(App\Models\User::findOrFail($input["actor"]), $input["data"], $input["fingerprint"]);
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
            $this->assertSame(1, ParticipationConfiguration::where('participation_id', $p->id)->whereNull('valid_until')->count());
            $this->assertSame(1, TutorAssignment::where('participation_id', $p->id)->whereNull('valid_until')->count());
        } finally {
            unlink($path);
            foreach ($tables as $table) {
                DB::table($table)->where('id', '>', $baseline[$table])->delete();
            }
        }
    }

    public function test_period_dates_cannot_exclude_existing_historical_practice_dates(): void
    {
        [$actor, $p, $data] = $this->context();
        $this->assign($actor, $data);
        $admin = $this->account('admin');
        $period = $p->period;
        $this->actingAs($admin)->put(route('academic.periods.update', $period), ['code' => $period->code, 'name' => $period->name, 'status' => 'active', 'starts_on' => '2026-11-15', 'ends_on' => '2026-12-31'])->assertSessionHasErrors('starts_on');
        $this->assertSame('2026-01-01', $period->fresh()->starts_on->toDateString());
    }

    public function test_group_picker_paginates_five_hundred_students_instead_of_loading_one_large_selector(): void
    {
        [$actor, $first] = $this->context();
        $first->student->user->update(['name' => 'Estudiante 000']);
        for ($i = 1; $i < 500; $i++) {
            $p = $this->student($actor, $first->academic_period_id, $first->career);
            $p->student->user->update(['name' => 'Estudiante '.str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
        }
        $group = PlacementGroup::factory()->create(['academic_period_id' => $first->academic_period_id, 'created_by' => $actor->id]);

        $this->actingAs($actor)->get(route('operational.catalog.show', ['groups', $group->id]))
            ->assertOk()->assertSee('500 estudiantes disponibles')->assertSee('Estudiante 000')->assertDontSee('Estudiante 030')
            ->assertViewHas('candidates', fn ($candidates) => $candidates->total() === 500 && $candidates->count() === 30);
        $this->get(route('operational.catalog.show', ['groups', $group->id, 'candidate_page' => 17]))
            ->assertOk()->assertSee('Estudiante 499')->assertDontSee('Estudiante 000')
            ->assertViewHas('candidates', fn ($candidates) => $candidates->count() === 20 && $candidates->currentPage() === 17);
    }

    public function test_bulk_membership_add_and_remove_preserve_existing_individual_assignment_and_audit_each_student(): void
    {
        [$actor, $first, $data] = $this->context();
        $this->assign($actor, $data);
        $second = $this->student($actor, $first->academic_period_id, $first->career);
        $group = PlacementGroup::factory()->create(['academic_period_id' => $first->academic_period_id, 'created_by' => $actor->id]);
        $payload = ['participation_ids' => [$first->id, $second->id], 'action' => 'add', 'reason' => 'Incorporación múltiple'];
        $this->get(route('operational.catalog.show', ['groups', $group->id]))->assertOk()->assertSee('Tiene asignación individual');

        $this->post(route('operational.memberships.store', $group), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('group_memberships', 2);
        $this->assertSame(2, AuditEvent::where('action', 'group.member.add')->count());
        $this->assertDatabaseCount('participation_configurations', 1);
        $this->assertNull(PlacementConfiguration::firstOrFail()->placement_group_id);
        $this->get(route('operational.catalog.show', ['groups', $group->id]))->assertViewHas('candidates', fn ($candidates) => $candidates->total() === 0);
        $this->get(route('operational.catalog.show', ['groups', $group->id, 'membership_mode' => 'remove']))->assertOk()->assertViewHas('candidates', fn ($candidates) => $candidates->total() === 2);
        $payload['action'] = 'remove';
        $payload['reason'] = 'Retiro múltiple';
        $this->post(route('operational.memberships.store', $group), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, GroupMembership::whereNull('valid_until')->count());
        $this->assertSame(2, AuditEvent::where('action', 'group.member.remove')->count());
        $this->assertDatabaseCount('participation_configurations', 1);
        $this->assertSame(1, TutorAssignment::whereNull('valid_until')->count());
    }

    public function test_bulk_membership_rejects_other_period_without_partial_incorporations(): void
    {
        [$actor, $first] = $this->context();
        $otherPeriod = Project::factory()->create()->academic_period_id;
        $other = $this->student($actor, $otherPeriod, $first->career);
        $group = PlacementGroup::factory()->create(['academic_period_id' => $first->academic_period_id, 'created_by' => $actor->id]);

        $this->actingAs($actor)->post(route('operational.memberships.store', $group), ['participation_ids' => [$first->id, $other->id], 'action' => 'add', 'reason' => 'Ingreso'])
            ->assertSessionHasErrors('participation_ids');
        $this->assertDatabaseCount('group_memberships', 0);
        $this->assertSame(0, AuditEvent::where('action', 'group.member.add')->count());
    }

    public function test_bulk_membership_rechecks_duplicates_and_closed_period_without_partial_changes(): void
    {
        [$actor, $first] = $this->context();
        $second = $this->student($actor, $first->academic_period_id, $first->career);
        $group = PlacementGroup::factory()->create(['academic_period_id' => $first->academic_period_id, 'created_by' => $actor->id]);
        $this->actingAs($actor)->post(route('operational.memberships.store', $group), ['participation_ids' => [$first->id], 'action' => 'add', 'reason' => 'Ingreso'])->assertSessionHasNoErrors();
        $payload = ['participation_ids' => [$first->id, $second->id], 'action' => 'add', 'reason' => 'Selección obsoleta'];

        $this->post(route('operational.memberships.store', $group), $payload)->assertSessionHasErrors('participation_ids');
        $this->assertDatabaseCount('group_memberships', 1);
        $first->period->update(['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now('UTC')]);
        $payload['participation_ids'] = [$second->id];
        $this->post(route('operational.memberships.store', $group), $payload)->assertSessionHasErrors('academic_period_id');
        $this->assertDatabaseCount('group_memberships', 1);
    }

    public function test_group_picker_filters_by_name_identification_and_career_and_excludes_inactive_students(): void
    {
        [$actor, $first] = $this->context();
        $first->student->user->update(['name' => 'Ana Selección', 'identification_number' => 'TEXT-001']);
        $second = $this->student($actor, $first->academic_period_id, $first->career);
        $second->student->user->forceFill(['name' => 'Ana Inactiva', 'status' => 'inactive'])->save();
        $otherCareer = Career::create(['code' => 'TEC', 'name' => 'Tecnología', 'status' => 'active']);
        $third = $this->student($actor, $first->academic_period_id, $otherCareer);
        $third->student->user->update(['name' => 'Luis Otra carrera']);
        $group = PlacementGroup::factory()->create(['academic_period_id' => $first->academic_period_id, 'created_by' => $actor->id]);
        $this->actingAs($actor);

        foreach (['Ana', 'TEXT-001'] as $search) {
            $this->get(route('operational.catalog.show', ['groups', $group->id, 'student_q' => $search]))->assertOk()
                ->assertViewHas('candidates', fn ($candidates) => $candidates->pluck('id')->all() === [$first->id]);
        }
        $this->get(route('operational.catalog.show', ['groups', $group->id, 'student_career_id' => $otherCareer->id]))->assertOk()
            ->assertViewHas('candidates', fn ($candidates) => $candidates->pluck('id')->all() === [$third->id]);
    }

    public function test_bulk_selection_validates_empty_duplicate_oversized_and_unauthorized_requests(): void
    {
        [$actor, $first] = $this->context();
        $group = PlacementGroup::factory()->create(['academic_period_id' => $first->academic_period_id, 'created_by' => $actor->id]);
        $base = ['action' => 'add', 'reason' => 'Ingreso'];
        $this->actingAs($actor)->post(route('operational.memberships.store', $group), $base)->assertSessionHasErrors('participation_ids');
        $this->post(route('operational.memberships.store', $group), $base + ['participation_ids' => [$first->id, $first->id]])->assertSessionHasErrors('participation_ids.0');
        $this->post(route('operational.memberships.store', $group), $base + ['participation_ids' => range(1, 501)])->assertSessionHasErrors('participation_ids');
        $this->post(route('operational.memberships.store', $group), $base + ['participation_ids' => [['unexpected']]])->assertSessionHasErrors('participation_ids.0');
        $this->get(route('operational.catalog.show', ['groups', $group->id]))->assertOk();
        $this->post(route('operational.memberships.store', $group), $base + ['participation_ids' => [99999999]])->assertSessionHasErrors('participation_ids');
        $this->actingAs($first->student->user)->post(route('operational.memberships.store', $group), $base + ['participation_ids' => [$first->id]])->assertForbidden();
        $this->assertDatabaseCount('group_memberships', 0);
    }

    public function test_bulk_group_incorporation_rejects_members_of_other_groups_atomically_and_hides_them_in_picker(): void
    {
        [$actor, $first] = $this->context();
        $second = $this->student($actor, $first->academic_period_id, $first->career);
        $group = PlacementGroup::factory()->create(['academic_period_id' => $first->academic_period_id, 'created_by' => $actor->id]);
        $other = PlacementGroup::factory()->create(['academic_period_id' => $first->academic_period_id, 'created_by' => $actor->id]);
        $this->actingAs($actor)->post(route('operational.memberships.store', $group), ['participation_ids' => [$first->id], 'action' => 'add', 'reason' => 'Ingreso'])->assertSessionHasNoErrors();
        $this->get(route('operational.catalog.show', ['groups', $other->id]))->assertOk()
            ->assertViewHas('candidates', fn ($candidates) => $candidates->pluck('id')->all() === [$second->id]);
        $this->post(route('operational.memberships.store', $other), ['participation_ids' => [$first->id, $second->id], 'action' => 'add', 'reason' => 'Ingreso masivo'])->assertSessionHasErrors('participation_ids');
        $this->assertDatabaseCount('group_memberships', 1);
        $this->assertDatabaseMissing('group_memberships', ['placement_group_id' => $other->id]);
    }

    public function test_same_student_can_belong_to_one_group_in_each_different_period(): void
    {
        [$actor, $first] = $this->context();
        $project = Project::factory()->create();
        $second = Participation::create(['student_id' => $first->student_id, 'academic_period_id' => $project->academic_period_id, 'career_id' => $first->career_id, 'student_career_record_id' => $first->student_career_record_id, 'status' => 'active', 'registered_by' => $actor->id, 'registered_at' => now('UTC')]);
        $this->actingAs($actor);
        foreach ([$first, $second] as $p) {
            $group = PlacementGroup::factory()->create(['academic_period_id' => $p->academic_period_id, 'created_by' => $actor->id]);
            $this->post(route('operational.memberships.store', $group), ['participation_ids' => [$p->id], 'action' => 'add', 'reason' => 'Ingreso'])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame(2, GroupMembership::whereNull('valid_until')->count());
    }

    public function test_database_rejects_second_current_group_membership_for_same_participation(): void
    {
        [$actor, $p] = $this->context();
        $first = PlacementGroup::factory()->create(['academic_period_id' => $p->academic_period_id, 'created_by' => $actor->id]);
        $other = PlacementGroup::factory()->create(['academic_period_id' => $p->academic_period_id, 'created_by' => $actor->id]);
        GroupMembership::factory()->create(['placement_group_id' => $first->id, 'participation_id' => $p->id, 'academic_period_id' => $p->academic_period_id, 'assigned_by' => $actor->id]);
        $this->expectException(QueryException::class);
        GroupMembership::factory()->create(['placement_group_id' => $other->id, 'participation_id' => $p->id, 'academic_period_id' => $p->academic_period_id, 'assigned_by' => $actor->id]);
    }

    private function assignedGroupContext(): array
    {
        [$actor, $first, $data] = $this->context();
        $second = $this->student($actor, $first->academic_period_id, $first->career);
        $group = PlacementGroup::factory()->create(['academic_period_id' => $first->academic_period_id, 'created_by' => $actor->id]);
        $this->actingAs($actor)->post(route('operational.memberships.store', $group), ['participation_ids' => [$first->id, $second->id], 'action' => 'add', 'reason' => 'Ingreso'])->assertSessionHasNoErrors();
        unset($data['participation_id']);
        $data['placement_group_id'] = $group->id;
        $this->assign($actor, $data);

        return [$actor, $first, $second, $group, $data];
    }

    public function test_individual_editor_prefills_applied_configuration_and_saves_directly_with_history(): void
    {
        [$actor, $p, $data] = $this->context();
        $this->assign($actor, $data);
        $old = $p->fresh()->currentConfiguration->configuration;
        $form = $this->get(route('operational.assignments.edit', $p))->assertOk()->assertSee('Editar horario y asignación')->assertSee('Guardar cambios')->assertSee('value="08:00"', false);
        $this->assertSame($data['institution_area_id'], $form->viewData('defaults')['institution_area_id']);
        $this->assertSame($data['teacher_id'], $form->viewData('defaults')['teacher_id']);
        $data['edit_token'] = $form->viewData('editToken');
        $data['intervals'][0]['starts_at'] = '09:00';
        $data['required_hours'] = 180;

        $this->put(route('operational.assignments.update', $p), $data)->assertRedirect(route('operational.assignments.show', $p))->assertSessionHasNoErrors();
        $current = $p->fresh()->currentConfiguration->configuration;
        $this->assertSame(2, $current->version_number);
        $this->assertSame(10800, $current->required_minutes);
        $this->assertSame('09:00:00', $current->intervals->first()->starts_at);
        $this->assertSame('08:00:00', $old->intervals->first()->starts_at);
        $this->assertNotNull($old->fresh()->valid_until);
        $this->assertDatabaseCount('tutor_assignments', 1);
    }

    public function test_saving_unchanged_editor_does_not_create_redundant_versions(): void
    {
        [$actor, $p, $data] = $this->context();
        $this->assign($actor, $data);
        $form = $this->get(route('operational.assignments.edit', $p))->assertOk();
        $data['edit_token'] = $form->viewData('editToken');
        $data['required_hours'] = '160.00';
        $data['intervals'] = array_reverse($data['intervals']);

        $this->put(route('operational.assignments.update', $p), $data)->assertRedirect()->assertSessionHas('success', 'No hay cambios que guardar.');
        $this->assertDatabaseCount('placement_configurations', 1);
        $this->assertDatabaseCount('participation_configurations', 1);
    }

    public function test_group_editor_prefills_common_configuration_and_preserves_unselected_individual_exceptions(): void
    {
        [$actor, $first, $second, $group, $data] = $this->assignedGroupContext();
        $exception = $this->student($actor, $first->academic_period_id, $first->career);
        $individual = $data;
        unset($individual['placement_group_id']);
        $individual['participation_id'] = $exception->id;
        $individual['required_hours'] = 90;
        $this->assign($actor, $individual);
        $exceptionConfiguration = $exception->fresh()->currentConfiguration->placement_configuration_id;
        $this->post(route('operational.memberships.store', $group), ['participation_ids' => [$exception->id], 'action' => 'add', 'reason' => 'Ingreso'])->assertSessionHasNoErrors();
        $form = $this->get(route('operational.groups.edit', $group))->assertOk()->assertSee('Configuración diferente / individual');
        $this->assertSame([$first->id, $second->id], $form->viewData('selectedIds'));
        $this->assertSame('08:00', $form->viewData('defaults')['intervals'][0]['starts_at']);
        $data['edit_token'] = $form->viewData('editToken');
        $data['selected_participation_ids'] = [$first->id, $second->id];
        $data['intervals'][0]['starts_at'] = '09:00';

        $this->put(route('operational.groups.update', $group), $data)->assertRedirect(route('operational.catalog.show', ['groups', $group->id]))->assertSessionHasNoErrors();
        foreach ([$first, $second] as $p) {
            $current = $p->fresh()->currentConfiguration->configuration;
            $this->assertSame($group->id, $current->placement_group_id);
            $this->assertSame('09:00:00', $current->intervals->first()->starts_at);
            $this->assertSame(2, $current->version_number);
        }
        $this->assertSame($exceptionConfiguration, $exception->fresh()->currentConfiguration->placement_configuration_id);
        $this->assertSame(5400, $exception->fresh()->currentConfiguration->configuration->required_minutes);
        $this->assertSame(3, TutorAssignment::whereNull('valid_until')->count());
        $nextForm = $this->get(route('operational.groups.edit', $group))->assertOk();
        $data['edit_token'] = $nextForm->viewData('editToken');
        $data['selected_participation_ids'] = [$exception->id];
        $data['intervals'][0]['starts_at'] = '10:00';
        $this->put(route('operational.groups.update', $group), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($group->id, $exception->fresh()->currentConfiguration->configuration->placement_group_id);
        $this->assertSame('10:00:00', $exception->fresh()->currentConfiguration->configuration->intervals->first()->starts_at);
        $this->assertSame('09:00:00', $first->fresh()->currentConfiguration->configuration->intervals->first()->starts_at);
    }

    public function test_editor_rejects_stale_individual_form_and_replayed_token(): void
    {
        [$actor, $p, $data] = $this->context();
        $this->assign($actor, $data);
        $first = $this->get(route('operational.assignments.edit', $p))->assertOk();
        $second = $this->get(route('operational.assignments.edit', $p))->assertOk();
        $data['edit_token'] = $first->viewData('editToken');
        $data['intervals'][0]['starts_at'] = '09:00';
        $this->put(route('operational.assignments.update', $p), $data)->assertSessionHasNoErrors();
        $this->put(route('operational.assignments.update', $p), $data)->assertSessionHasErrors('edit_token');
        $data['edit_token'] = $second->viewData('editToken');
        $data['intervals'][0]['starts_at'] = '10:00';
        $this->put(route('operational.assignments.update', $p), $data)->assertSessionHasErrors('edit_token');
        $this->assertDatabaseCount('placement_configurations', 2);
        $this->assertSame('09:00:00', $p->fresh()->currentConfiguration->configuration->intervals->first()->starts_at);
    }

    public function test_group_editor_rejects_changed_membership_and_foreign_student_selection(): void
    {
        [$actor, $first, $second, $group, $data] = $this->assignedGroupContext();
        $outsider = $this->student($actor, $first->academic_period_id, $first->career);
        $form = $this->get(route('operational.groups.edit', $group))->assertOk();
        $data['edit_token'] = $form->viewData('editToken');
        $data['selected_participation_ids'] = [$first->id, $outsider->id];
        $data['intervals'][0]['starts_at'] = '09:00';
        $this->put(route('operational.groups.update', $group), $data)->assertSessionHasErrors('selected_participation_ids');
        $this->post(route('operational.memberships.store', $group), ['participation_ids' => [$outsider->id], 'action' => 'add', 'reason' => 'Ingreso'])->assertSessionHasNoErrors();
        $data['selected_participation_ids'] = [$first->id];
        $this->put(route('operational.groups.update', $group), $data)->assertSessionHasErrors('edit_token');
        $this->assertDatabaseCount('placement_configurations', 2);
    }

    public function test_editor_enforces_coordinator_permission_closed_period_and_target_scoping(): void
    {
        [$actor, $first, $second, $group, $data] = $this->assignedGroupContext();
        $form = $this->get(route('operational.assignments.edit', $first))->assertOk();
        $data['edit_token'] = $form->viewData('editToken');
        unset($data['placement_group_id']);
        $this->put(route('operational.assignments.update', $second), $data)->assertSessionHasErrors('edit_token');
        foreach (['admin', 'tutor', 'student'] as $role) {
            $account = $this->account($role);
            $this->actingAs($account)->get(route('operational.assignments.edit', $first))->assertForbidden();
            $this->get(route('operational.groups.edit', $group))->assertForbidden();
            $this->put(route('operational.assignments.update', $first), $data)->assertForbidden();
        }
        $first->period->update(['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now('UTC')]);
        $this->actingAs($actor)->put(route('operational.assignments.update', $first), $data)->assertSessionHasErrors('academic_period_id');
        $this->get(route('operational.groups.edit', $group))->assertSessionHasErrors('academic_period_id');
        $this->assertDatabaseCount('placement_configurations', 2);
    }

    public function test_editor_can_change_institution_teacher_and_project_without_false_concurrency_conflict(): void
    {
        [$actor, $p, $data] = $this->context();
        $this->assign($actor, $data);
        $old = $p->fresh()->currentConfiguration->configuration;
        $form = $this->get(route('operational.assignments.edit', $p))->assertOk();
        $institution = HostInstitution::factory()->create();
        $area = InstitutionArea::factory()->create(['host_institution_id' => $institution->id]);
        $institutionalTutor = InstitutionalTutor::factory()->create(['host_institution_id' => $institution->id]);
        $teacher = Teacher::create(['user_id' => $this->account('tutor')->id, 'status' => 'active', 'created_by' => $actor->id]);
        $project = Project::factory()->create(['academic_period_id' => $p->academic_period_id]);
        $data = array_replace($data, ['edit_token' => $form->viewData('editToken'), 'project_id' => $project->id, 'host_institution_id' => $institution->id, 'institution_area_id' => $area->id, 'institutional_tutor_id' => $institutionalTutor->id, 'teacher_id' => $teacher->id]);

        $this->put(route('operational.assignments.update', $p), $data)->assertRedirect()->assertSessionHasNoErrors();
        $current = $p->fresh()->currentConfiguration->configuration;
        $this->assertSame($institution->id, $current->host_institution_id);
        $this->assertSame($project->id, $p->fresh()->project_id);
        $this->assertSame($teacher->id, $p->fresh()->currentTutor->teacher_id);
        $this->assertSame('Hospital de prueba', $old->fresh()->institution_name_snapshot);
        $this->assertSame(2, TutorAssignment::count());
    }

    public function test_editor_rejects_overlapping_schedule_and_keeps_entered_values_for_correction(): void
    {
        [$actor, $p, $data] = $this->context();
        $this->assign($actor, $data);
        $form = $this->get(route('operational.assignments.edit', $p))->assertOk();
        $data['edit_token'] = $form->viewData('editToken');
        $data['intervals'][1]['starts_at'] = '11:00';

        $this->put(route('operational.assignments.update', $p), $data)->assertSessionHasErrors('intervals');
        $this->get(route('operational.assignments.edit', $p))->assertOk()->assertSee('value="11:00"', false);
        $this->assertDatabaseCount('placement_configurations', 1);
    }

    public function test_editor_session_expires_without_changing_configuration(): void
    {
        [$actor, $p, $data] = $this->context();
        $this->assign($actor, $data);
        $form = $this->get(route('operational.assignments.edit', $p))->assertOk();
        $data['edit_token'] = $form->viewData('editToken');
        $data['intervals'][0]['starts_at'] = '09:00';
        $this->travel(31)->minutes();

        $this->put(route('operational.assignments.update', $p), $data)->assertSessionHasErrors('edit_token');
        $this->assertDatabaseCount('placement_configurations', 1);
    }
}
