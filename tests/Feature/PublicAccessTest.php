<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class PublicAccessTest extends TestCase
{
    public function test_login_page_is_available_without_database_writes(): void
    {
        $this->get('/login')->assertOk()->assertSee('Correo electrónico')->assertSee('SIGEV');
    }

    public function test_guests_cannot_access_administration(): void
    {
        foreach (['/panel', '/usuarios', '/usuarios/crear', '/auditoria', '/perfil'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_public_registration_is_unavailable(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_home_redirects_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_tutor_dashboard_and_profile_render_without_academic_tables(): void
    {
        $user = new User(['name' => 'Cuenta en memoria', 'email' => 'memoria@itsup.edu.ec']);
        $user->id = 123;
        $user->status = 'active';
        $user->must_change_password = false;
        $user->setRelation('roles', new Collection([new Role(['code' => 'tutor'])]));
        $this->actingAs($user)->get('/panel')->assertOk()->assertSee('Su cuenta está preparada');
        $this->get('/perfil')->assertOk()->assertSee('Cambiar contraseña');
        $this->get('/usuarios')->assertForbidden();
    }

    public function test_administrative_creation_form_renders_with_in_memory_actor(): void
    {
        $user = new User(['name' => 'Cuenta en memoria', 'email' => 'memoria@itsup.edu.ec']);
        $user->id = 123;
        $user->status = 'active';
        $user->must_change_password = false;
        $user->setRelation('roles', new Collection([new Role(['code' => 'admin'])]));
        $this->actingAs($user)->get('/usuarios/crear')->assertOk()->assertSee('Roles de acceso');
    }

    public function test_first_access_redirect_is_enforced_without_database_writes(): void
    {
        $user = new User;
        $user->id = 123;
        $user->status = 'active';
        $user->must_change_password = true;
        $user->setRelation('roles', new Collection([new Role(['code' => 'admin'])]));
        $this->actingAs($user)->get('/usuarios')->assertRedirect('/perfil');
    }

    public function test_model_dates_are_persisted_and_read_as_utc(): void
    {
        $user = new User;
        $user->last_login_at = '2026-11-09 18:30:00.123456';
        $this->assertSame('UTC', $user->freshTimestamp()->timezoneName);
        $this->assertSame('UTC', $user->last_login_at->timezoneName);
        $this->assertSame('13:30', $user->last_login_at->timezone('America/Guayaquil')->format('H:i'));
    }
}
