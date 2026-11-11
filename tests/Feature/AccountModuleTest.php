<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\UserManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountModuleTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = config('database.default');
        $database = config("database.connections.$connection.database");
        if (getenv('SIGEV_TEST_DB_AUTHORIZED') !== '1' || ! is_string($database) || ! str_ends_with($database, '_test') || $database === 'sigev' || $connection !== 'mysql') {
            $this->markTestSkipped('Requiere base MariaDB independiente *_test, esquema preparado y autorización SIGEV_TEST_DB_AUTHORIZED=1. Nunca se ejecuta contra sigev.');
        }
        DB::beginTransaction();
        $this->transactionStarted = true;
        foreach (Role::LABELS as $code => $name) {
            Role::firstOrCreate(['code' => $code], ['name' => $name]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function account(string $role = 'admin', bool $initial = false): User
    {
        $user = new User(['name' => 'Prueba aislada', 'identification_type' => 'otro', 'identification_number' => bin2hex(random_bytes(8)), 'email' => bin2hex(random_bytes(8)).'@itsup.edu.ec']);
        $user->password = Hash::make('Temporal-Para-Prueba42!');
        $user->status = 'active';
        $user->must_change_password = $initial;
        $user->save();
        DB::table('role_user')->insert(['role_id' => Role::where('code', $role)->value('id'), 'user_id' => $user->id, 'assigned_by' => $user->id, 'assigned_at' => now('UTC'), 'created_at' => now('UTC')]);

        return $user;
    }

    private function data(array $roles = ['tutor']): array
    {
        return ['name' => 'Cuenta de prueba aislada', 'identification_type' => 'otro', 'identification_number' => bin2hex(random_bytes(8)), 'email' => bin2hex(random_bytes(8)).'@itsup.edu.ec', 'status' => 'active', 'roles' => $roles];
    }

    public function test_valid_login_and_logout(): void
    {
        $user = $this->account();
        $this->post('/login', ['email' => $user->email, 'password' => 'Temporal-Para-Prueba42!'])->assertRedirect('/panel');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_incorrect_password_and_suspended_account_are_rejected(): void
    {
        $user = $this->account();
        $this->post('/login', ['email' => $user->email, 'password' => 'Incorrecta'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $user->status = 'suspended';
        $user->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'Temporal-Para-Prueba42!'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_first_access_requires_password_change(): void
    {
        $user = $this->account(initial: true);
        $this->actingAs($user)->get('/panel')->assertRedirect('/perfil');
        $this->put('/perfil/password', ['current_password' => 'Temporal-Para-Prueba42!', 'password' => 'Nueva-Clave-Segura42!', 'password_confirmation' => 'Nueva-Clave-Segura42!'])->assertRedirect('/panel');
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertNotNull($user->fresh()->password_changed_at);
    }

    public function test_tutor_and_student_cannot_administer_accounts(): void
    {
        foreach (['tutor', 'student'] as $role) {
            $user = $this->account($role);
            $this->actingAs($user)->get('/usuarios')->assertForbidden();
            $this->post('/usuarios', $this->data())->assertForbidden();
        }
    }

    public function test_user_creation_duplicate_identity_and_secret_free_audit(): void
    {
        $admin = $this->account();
        $data = $this->data();
        $this->actingAs($admin)->post('/usuarios', $data)->assertRedirect('/usuarios');
        $created = User::where('email', $data['email'])->firstOrFail();
        $this->assertTrue(Hash::check($data['identification_number'], $created->password));
        $this->post('/usuarios', $data)->assertSessionHasErrors(['email', 'identification_number']);
        $audit = AuditEvent::where('entity_id', $created->id)->get()->toJson();
        $this->assertStringNotContainsString($created->password, $audit);
        $this->assertStringNotContainsString($data['identification_number'], $audit);
        $this->assertStringNotContainsString('remember_token', $audit);
    }

    public function test_second_active_coordinator_is_rejected(): void
    {
        $admin = $this->account();
        $this->account('coordinator');
        $this->actingAs($admin)->post('/usuarios', $this->data(['coordinator']))->assertSessionHasErrors('roles');
    }

    public function test_reactivation_of_suspended_second_coordinator_is_rejected(): void
    {
        $admin = $this->account();
        $first = $this->account('coordinator');
        $second = app(UserManager::class)->save($admin, null, array_merge($this->data(['coordinator']), ['status' => 'suspended']));
        $this->actingAs($admin)->put('/usuarios/'.$second->id, array_merge($this->data(['coordinator']), ['identification_number' => $second->identification_number, 'email' => $second->email]))->assertSessionHasErrors('roles');
        $this->assertSame('suspended', $second->fresh()->status);
    }

    public function test_last_admin_cannot_be_deactivated_or_lose_role(): void
    {
        $admin = $this->account();
        $data = array_merge($this->data(), ['email' => $admin->email, 'identification_number' => $admin->identification_number, 'roles' => []]);
        $this->actingAs($admin)->put('/usuarios/'.$admin->id, $data)->assertSessionHasErrors('status');
        $data['roles'] = ['admin'];
        $data['status'] = 'inactive';
        $this->put('/usuarios/'.$admin->id, $data)->assertSessionHasErrors('status');
    }

    public function test_role_reactivation_reuses_pivot(): void
    {
        $admin = $this->account();
        $data = $this->data();
        $manager = app(UserManager::class);
        $user = $manager->save($admin, null, $data);
        $pivot = DB::table('role_user')->where('user_id', $user->id)->value('id');
        $manager->save($admin, $user, array_merge($data, ['roles' => []]));
        $manager->save($admin, $user, $data);
        $this->assertSame($pivot, DB::table('role_user')->where('user_id', $user->id)->value('id'));
        $this->assertSame(1, DB::table('role_user')->where('user_id', $user->id)->count());
    }

    public function test_coordinator_can_only_create_tutors(): void
    {
        $coordinator = $this->account('coordinator');
        $this->actingAs($coordinator)->post('/usuarios', $this->data(['admin']))->assertSessionHasErrors('roles');
        $this->post('/usuarios', $this->data(['tutor']))->assertRedirect('/panel');
        $this->get('/usuarios')->assertForbidden();
    }
}
