<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\AuditEvent;
use App\Models\Career;
use App\Models\Participation;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentCareerRecord;
use App\Models\StudentImport;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InstitutionalWorkbook;
use App\Services\StudentImportService;
use App\Services\UserManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class AcademicModuleTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function account(string $code = 'admin'): User
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
        $admin = $this->account();
        $career = Career::create(['code' => 'ENF', 'name' => 'Enfermería', 'status' => 'active']);
        $period = AcademicPeriod::create(['code' => 'PI-2026', 'name' => 'Primer período', 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30', 'status' => 'active', 'created_by' => $admin->id]);

        return [$admin, $career, $period];
    }

    private function row(string $id = '0001234567', string $email = 'estudiante@itsup.edu.ec', string $career = 'ENF'): array
    {
        return ['cedula', $id, 'Estudiante de prueba', $email, $career];
    }

    private function excel(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sigev-test-');
        app(InstitutionalWorkbook::class)->write($path, $rows);
        $upload = UploadedFile::fake()->createWithContent('estudiantes.xlsx', file_get_contents($path));
        unlink($path);

        return $upload;
    }

    private function batch(User $admin, AcademicPeriod $period, array $rows): StudentImport
    {
        return app(StudentImportService::class)->preview($admin, $period, $this->excel($rows));
    }

    private function process(User $admin, StudentImport $batch): void
    {
        app(StudentImportService::class)->confirm($admin, $batch);
    }

    public function test_admin_can_create_edit_and_deactivate_career(): void
    {
        $admin = $this->account();
        $this->actingAs($admin)->post(route('academic.careers.store'), ['code' => 'ENF', 'name' => 'Enfermería', 'status' => 'active'])->assertRedirect(route('academic.careers.index'));
        $career = Career::firstOrFail();
        $this->put(route('academic.careers.update', $career), ['code' => 'ENF', 'name' => 'Enfermería actualizada', 'status' => 'inactive'])->assertRedirect();
        $this->assertSame('inactive', $career->fresh()->status);
        $this->assertSame(2, AuditEvent::where('entity_type', 'careers')->count());
    }

    public function test_career_code_cannot_be_duplicated(): void
    {
        [$admin] = $this->context();
        $this->actingAs($admin)->post(route('academic.careers.store'), ['code' => 'ENF', 'name' => 'Otra', 'status' => 'active'])->assertSessionHasErrors('code');
        $this->assertSame(1, Career::count());
    }

    public function test_period_dates_are_validated_and_closed_period_cannot_be_modified(): void
    {
        [$admin,,$period] = $this->context();
        $data = ['code' => 'PI-2026', 'name' => 'Período', 'starts_on' => '2026-06-01', 'ends_on' => '2026-01-01', 'status' => 'active'];
        $this->actingAs($admin)->put(route('academic.periods.update', $period), $data)->assertSessionHasErrors('ends_on');
        $data['ends_on'] = '2026-06-30';
        $data['status'] = 'closed';
        $this->put(route('academic.periods.update', $period), $data)->assertRedirect();
        $this->assertSame('closed', $period->fresh()->status);
        $this->assertNotNull($period->fresh()->closed_by);
        $data['status'] = 'active';
        $this->put(route('academic.periods.update', $period), $data)->assertSessionHasErrors('status');
    }

    public function test_new_period_can_be_created(): void
    {
        $admin = $this->account();
        $this->actingAs($admin)->post(route('academic.periods.store'), ['code' => 'PII', 'name' => 'Segundo período', 'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'status' => 'planned'])->assertRedirect();
        $this->assertSame('planned', AcademicPeriod::firstOrFail()->status);
    }

    public function test_preview_creates_no_accounts_profiles_or_participations(): void
    {
        [$admin,,$period] = $this->context();
        $count = User::count();
        $batch = $this->batch($admin, $period, [$this->row()]);
        $this->assertSame($count, User::count());
        $this->assertSame(0, Student::count());
        $this->assertSame(0, Participation::count());
        $this->assertSame('valid', $batch->rows()->first()->status);
    }

    public function test_http_upload_and_confirmation_create_student_with_required_security(): void
    {
        [$admin,,$period] = $this->context();
        $this->actingAs($admin)->post(route('academic.imports.preview'), ['academic_period_id' => $period->id, 'file' => $this->excel([$this->row()])])->assertRedirect();
        $batch = StudentImport::firstOrFail();
        $this->post(route('academic.imports.confirm', $batch), ['confirmation' => '1'])->assertRedirect();
        $student = Student::with('user')->firstOrFail();
        $this->assertSame('0001234567', $student->user->identification_number);
        $this->assertTrue(Hash::check('0001234567', $student->user->password));
        $this->assertTrue($student->user->must_change_password);
        $this->assertTrue($student->user->hasRole('student'));
        $this->assertSame(1, Participation::count());
        $this->assertNull(Participation::first()->project_id);
        $this->assertSame('created', $batch->rows()->first()->status);
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertGreaterThan(0, AuditEvent::where('action', 'import.completed')->count());
    }

    public function test_reimport_and_repeated_confirmation_do_not_duplicate_accounts_or_history(): void
    {
        [$admin,,$period] = $this->context();
        $one = $this->batch($admin, $period, [$this->row()]);
        $this->process($admin, $one);
        $hash = Student::first()->user->password;
        $two = $this->batch($admin, $period, [$this->row()]);
        $this->process($admin, $two);
        $this->process($admin, $two->fresh());
        $this->assertSame(2, User::count());
        $this->assertSame(1, Student::count());
        $this->assertSame(1, Participation::count());
        $this->assertSame(1, StudentCareerRecord::count());
        $this->assertSame($hash, Student::first()->user->password);
        $this->assertSame('reused', $two->rows()->first()->status);
    }

    public function test_existing_suspended_account_keeps_credentials_status_and_identity(): void
    {
        [$admin,,$period] = $this->context();
        $user = $this->account('student');
        $user->status = 'suspended';
        $user->save();
        $hash = $user->password;
        $batch = $this->batch($admin, $period, [[$user->identification_type, $user->identification_number, 'Otro nombre propuesto', $user->email, 'ENF']]);
        $this->process($admin, $batch);
        $this->assertSame('suspended', $user->fresh()->status);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame($user->name, $user->fresh()->name);
        $this->assertSame('reused', $batch->rows()->first()->status);
    }

    public function test_incompatible_email_and_identification_are_rejected(): void
    {
        [$admin,,$period] = $this->context();
        $one = $this->account('student');
        $two = $this->account('student');
        $batch = $this->batch($admin, $period, [[$one->identification_type, $one->identification_number, $one->name, $two->email, 'ENF']]);
        $this->process($admin, $batch);
        $this->assertSame('identity_conflict', $batch->rows()->first()->errors['email']);
        $this->assertSame(0, Participation::count());
    }

    public function test_missing_and_inactive_careers_are_rejected_by_row(): void
    {
        [$admin,$career,$period] = $this->context();
        $career->update(['status' => 'inactive']);
        $batch = $this->batch($admin, $period, [$this->row(), $this->row('0009999999', 'otro@itsup.edu.ec', 'NOEXISTE')]);
        $this->process($admin, $batch);
        $this->assertSame(2, $batch->rows()->where('status', 'error')->count());
        $this->assertSame(0, Student::count());
    }

    public function test_duplicate_rows_are_not_silently_imported(): void
    {
        [$admin,,$period] = $this->context();
        $batch = $this->batch($admin, $period, [$this->row(), $this->row()]);
        $this->process($admin, $batch);
        $this->assertSame(2, $batch->rows()->where('status', 'error')->count());
        $this->assertSame(0, Participation::count());
    }

    public function test_conflicting_career_does_not_overwrite_participation(): void
    {
        [$admin,$career,$period] = $this->context();
        $batch = $this->batch($admin, $period, [$this->row()]);
        $this->process($admin, $batch);
        Career::create(['code' => 'TIC', 'name' => 'Tecnología', 'status' => 'active']);
        $second = $this->batch($admin, $period, [$this->row(career: 'TIC')]);
        $this->process($admin, $second);
        $this->assertSame('career_conflict', $second->rows()->first()->errors['career_code']);
        $this->assertSame($career->id, Participation::first()->career_id);
        $this->assertSame(1, Participation::count());
    }

    public function test_same_student_can_participate_in_another_period_without_new_identity(): void
    {
        [$admin,,$period] = $this->context();
        $this->process($admin, $this->batch($admin, $period, [$this->row()]));
        $other = AcademicPeriod::create(['code' => 'PII', 'name' => 'Segundo', 'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'status' => 'active', 'created_by' => $admin->id]);
        $this->process($admin, $this->batch($admin, $other, [$this->row()]));
        $this->assertSame(2, Participation::count());
        $this->assertSame(1, Student::count());
        $this->assertSame(2, User::count());
        $this->assertSame(1, StudentCareerRecord::count());
    }

    public function test_confirm_revalidates_career_changes_after_preview(): void
    {
        [$admin,$career,$period] = $this->context();
        $batch = $this->batch($admin, $period, [$this->row()]);
        $career->update(['status' => 'inactive']);
        $this->process($admin, $batch);
        $this->assertSame('career_inactive', $batch->rows()->first()->errors['career_code']);
        $this->assertSame(0, Student::count());
    }

    public function test_closed_period_and_pending_import_close_are_blocked(): void
    {
        [$admin,,$period] = $this->context();
        $batch = $this->batch($admin, $period, [$this->row()]);
        $data = ['code' => $period->code, 'name' => $period->name, 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30', 'status' => 'closed'];
        $this->actingAs($admin)->put(route('academic.periods.update', $period), $data)->assertSessionHasErrors('status');
        $this->post(route('academic.imports.cancel', $batch))->assertRedirect();
        $this->put(route('academic.periods.update', $period), $data)->assertRedirect();
        $this->post(route('academic.imports.preview'), ['academic_period_id' => $period->id, 'file' => $this->excel([$this->row()])])->assertSessionHasErrors('academic_period_id');
    }

    public function test_existing_teacher_profile_does_not_create_new_user_or_reset_password(): void
    {
        $admin = $this->account();
        $tutor = $this->account('tutor');
        $hash = $tutor->password;
        $this->actingAs($admin)->put(route('academic.teachers.update', $tutor), ['academic_title' => 'Licenciado', 'status' => 'active'])->assertRedirect();
        $this->assertSame('Licenciado', Teacher::where('user_id', $tutor->id)->first()->academic_title);
        $this->assertSame(2, User::count());
        $this->assertSame($hash, $tutor->fresh()->password);
        $this->actingAs($tutor)->put(route('academic.teachers.update', $tutor), ['academic_title' => 'Magíster'])->assertRedirect();
        $this->assertSame('Magíster', Teacher::first()->academic_title);
    }

    public function test_new_tutor_from_existing_user_manager_gets_minimal_profile(): void
    {
        $admin = $this->account();
        $tutor = app(UserManager::class)->save($admin, null, ['name' => 'Tutor', 'email' => 'nuevo@itsup.edu.ec', 'identification_type' => 'otro', 'identification_number' => '000888', 'status' => 'active', 'roles' => ['tutor']]);
        $this->assertSame($tutor->id, Teacher::firstOrFail()->user_id);
        $this->assertTrue($tutor->must_change_password);
    }

    public function test_non_admin_cannot_import_or_manage_academic_catalogs(): void
    {
        foreach (['coordinator', 'tutor', 'student'] as $role) {
            $user = $this->account($role);
            $this->actingAs($user)->get(route('academic.imports.create'))->assertForbidden();
            $this->get(route('academic.careers.index'))->assertForbidden();
            $this->post(route('academic.imports.preview'), [])->assertForbidden();
            $this->assertFalse(Gate::forUser($user)->allows('manage-academic'));
        }
        $this->assertSame(0, StudentImport::count());
    }

    public function test_coordinator_can_edit_teacher_profile_but_not_privileged_identity(): void
    {
        $coordinator = $this->account('coordinator');
        $tutor = $this->account('tutor');
        $this->actingAs($coordinator)->put(route('academic.teachers.update', $tutor), ['academic_title' => 'Docente', 'status' => 'active'])->assertRedirect();
        $this->get(route('academic.teachers.index'))->assertOk();
        $this->get(route('academic.periods.index'))->assertForbidden();
    }

    public function test_template_download_and_academic_pages_render(): void
    {
        [$admin,,$period] = $this->context();
        $this->actingAs($admin)->get(route('academic.imports.template'))->assertDownload('plantilla-estudiantes-itsup.xlsx');
        foreach (['academic.careers.index', 'academic.careers.create', 'academic.periods.index', 'academic.periods.create', 'academic.students.index', 'academic.imports.create', 'academic.imports.index', 'academic.teachers.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $batch = $this->batch($admin, $period, [$this->row()]);
        $this->get(route('academic.imports.show', $batch))->assertOk()->assertSee('Vista previa');
        $this->process($admin, $batch);
        $this->get(route('academic.students.index'))->assertOk()->assertSee('0001234567');
        $this->get(route('academic.imports.show', $batch))->assertOk()->assertSee('Creado');
    }

    public function test_formulas_are_rejected_and_audit_never_contains_initial_credential(): void
    {
        [$admin,,$period] = $this->context();
        $upload = $this->excel([$this->row()]);
        $zip = new ZipArchive;
        $zip->open($upload->getRealPath());
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $sheet = str_replace('<c r="C2" t="inlineStr" s="0">', '<c r="C2" t="inlineStr" s="0"><f>1+1</f>', $sheet);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        $batch = app(StudentImportService::class)->preview($admin, $period, $upload);
        $this->assertSame('formula_not_allowed', $batch->rows()->first()->errors['name']);
        $this->process($admin, $batch);
        $this->assertSame(0, Student::count());
        $valid = $this->batch($admin, $period, [$this->row()]);
        $this->process($admin, $valid);
        $this->assertStringNotContainsString('0001234567', AuditEvent::all()->toJson());
    }

    public function test_existing_project_is_preserved_when_template_does_not_request_a_project(): void
    {
        [$admin,$career,$period] = $this->context();
        $this->process($admin, $this->batch($admin, $period, [$this->row()]));
        $project = DB::table('projects')->insertGetId(['academic_period_id' => $period->id, 'career_id' => $career->id, 'code' => 'PROY-1', 'name' => 'Proyecto', 'status' => 'active', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        Participation::first()->update(['project_id' => $project]);
        $second = $this->batch($admin, $period, [$this->row()]);
        $this->process($admin, $second);
        $this->assertSame('reused', $second->rows()->first()->status);
        $this->assertSame($project, Participation::first()->project_id);
    }

    public function test_failure_inside_row_rolls_back_account_profile_role_and_participation(): void
    {
        [$admin,,$period] = $this->context();
        $batch = $this->batch($admin, $period, [$this->row()]);
        $real = app(UserManager::class);
        $manager = \Mockery::mock(UserManager::class);
        $manager->shouldReceive('save')->once()->andReturnUsing(function ($actor, $subject, $data) use ($real) {
            $real->save($actor, $subject, $data);
            throw new \RuntimeException('Simulación de fallo interno');
        });
        $service = new StudentImportService(app(InstitutionalWorkbook::class), $manager, app(AuditLogger::class));
        $service->confirm($admin, $batch);
        $this->assertSame(1, User::count());
        $this->assertSame(0, Student::count());
        $this->assertSame(0, Participation::count());
        $this->assertSame(1, DB::table('role_user')->count());
        $this->assertSame('processing_conflict', $batch->rows()->first()->errors['row']);
    }

    public function test_numeric_identification_is_rejected_to_protect_leading_zeroes(): void
    {
        [$admin,,$period] = $this->context();
        $upload = $this->excel([$this->row()]);
        $zip = new ZipArchive;
        $zip->open($upload->getRealPath());
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $sheet = str_replace('<c r="B2" t="inlineStr" s="0"><is><t xml:space="preserve">0001234567</t></is></c>', '<c r="B2"><v>1234567</v></c>', $sheet);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        $batch = app(StudentImportService::class)->preview($admin, $period, $upload);
        $this->assertSame('identification_must_be_text', $batch->rows()->first()->errors['identification_number']);
    }

    public function test_multiple_processing_requests_resume_without_duplicate_rows(): void
    {
        [$admin,,$period] = $this->context();
        $rows = [];
        for ($i = 0; $i < 27; $i++) {
            $rows[] = $this->row('000'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'alumno'.$i.'@itsup.edu.ec');
        }
        $batch = $this->batch($admin, $period, $rows);
        $this->process($admin, $batch);
        $this->assertSame('processing', $batch->fresh()->status);
        $this->assertSame(25, Participation::count());
        $this->process($admin, $batch->fresh());
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(27, Participation::count());
        $this->process($admin, $batch->fresh());
        $this->assertSame(27, Participation::count());
    }

    public function test_confirmation_fails_if_period_closed_after_preview(): void
    {
        [$admin,,$period] = $this->context();
        $batch = $this->batch($admin, $period, [$this->row()]);
        $period->update(['status' => 'closed', 'closed_by' => $admin->id, 'closed_at' => now('UTC')]);
        $this->actingAs($admin)->post(route('academic.imports.confirm', $batch), ['confirmation' => '1'])->assertSessionHasErrors('file');
        $this->assertSame(0, Participation::count());
    }

    public function test_database_enforces_one_participation_per_student_and_period(): void
    {
        [$admin,,$period] = $this->context();
        $this->process($admin, $this->batch($admin, $period, [$this->row()]));
        $existing = Participation::firstOrFail();
        $this->expectException(UniqueConstraintViolationException::class);
        $existing->replicate()->save();
    }

    public function test_uploaded_names_are_escaped_in_preview(): void
    {
        [$admin,,$period] = $this->context();
        $row = $this->row();
        $row[2] = '<script>alert(1)</script>';
        $batch = $this->batch($admin, $period, [$row]);
        $this->actingAs($admin)->get(route('academic.imports.show', $batch))->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_revoked_teacher_role_preserves_profile_without_reassigning_role(): void
    {
        $admin = $this->account();
        $tutor = $this->account('tutor');
        Teacher::create(['user_id' => $tutor->id, 'academic_title' => 'Licenciado', 'status' => 'active', 'created_by' => $admin->id]);
        DB::table('role_user')->where('user_id', $tutor->id)->update(['revoked_at' => now('UTC')]);
        $this->actingAs($admin)->get(route('academic.teachers.index'))->assertOk()->assertSee($tutor->name);
        $this->put(route('academic.teachers.update', $tutor), ['academic_title' => 'Magíster', 'status' => 'inactive'])->assertRedirect();
        $this->assertSame('Magíster', Teacher::first()->academic_title);
        $this->assertFalse($tutor->fresh()->roles()->exists());
    }
}
