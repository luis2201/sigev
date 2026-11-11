<?php

namespace Tests\Unit;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

class AccountRoleTest extends TestCase
{
    public function test_suspended_accounts_have_no_privileged_access(): void
    {
        $user = new User;
        $user->status = 'suspended';
        $user->setRelation('roles', new Collection([new Role(['code' => 'admin'])]));
        $this->assertFalse($user->hasRole('admin'));
    }

    public function test_active_account_only_has_its_assigned_roles(): void
    {
        $user = new User;
        $user->status = 'active';
        $user->setRelation('roles', new Collection([new Role(['code' => 'tutor'])]));
        $this->assertTrue($user->hasRole('tutor'));
        $this->assertFalse($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('coordinator'));
    }
}
