<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserManager
{
    public function __construct(private AuditLogger $audit) {}

    // Every role/status mutation takes the same globally ordered role locks.
    // This also serializes first-admin creation and last-admin protection.
    private function lockRoles(): void
    {
        foreach (Role::LABELS as $code => $label) {
            DB::table('roles')->insertOrIgnore(['code' => $code, 'name' => $label, 'created_at' => now('UTC')]);
        }
        Role::orderBy('id')->lockForUpdate()->get();
    }

    public function save(?User $actor, ?User $subject, array $data, ?string $bootstrapPassword = null): User
    {
        return DB::transaction(function () use ($actor, $subject, $data, $bootstrapPassword) {
            $this->lockRoles();
            // Recheck actor after the role lock: stale browser sessions never grant privileges.
            if ($actor) {
                $actor = User::lockForUpdate()->findOrFail($actor->id);
                $actor->load('roles');
                abort_unless($actor->hasRole('admin') || ($actor->hasRole('coordinator') && $subject === null && $data['roles'] === ['tutor']), 403);
                abort_if($actor->must_change_password, 403);
            } else {
                abort_unless(app()->runningInConsole() && $bootstrapPassword !== null, 403);
                $adminRole = Role::where('code', 'admin')->firstOrFail();
                if (DB::table('role_user')->where('role_id', $adminRole->id)->exists()) {
                    throw ValidationException::withMessages(['email' => 'Ya existe una cuenta administradora; la inicialización está cerrada.']);
                }
            }
            $isNew = $subject === null;
            $user = $subject ? User::lockForUpdate()->findOrFail($subject->id) : new User;
            $oldStatus = $user->status;
            $oldRoles = $isNew ? [] : $user->roles()->pluck('code')->all();
            $newRoles = $data['roles'];
            if (! $actor) {
                $newRoles = ['admin'];
            }
            $adminRole = Role::where('code', 'admin')->firstOrFail();
            if (! $isNew && $oldStatus === 'active' && in_array('admin', $oldRoles, true) && ($data['status'] !== 'active' || ! in_array('admin', $newRoles, true))) {
                $others = $adminRole->users()->where('users.status', 'active')->where('users.id', '!=', $user->id)->exists();
                if (! $others) {
                    throw ValidationException::withMessages(['status' => 'Debe conservarse al menos un administrador activo.']);
                }
            }
            if ($data['status'] === 'active' && in_array('coordinator', $newRoles, true)) {
                $exists = Role::where('code', 'coordinator')->firstOrFail()->users()->where('users.status', 'active')->when(! $isNew, fn ($q) => $q->where('users.id', '!=', $user->id))->exists();
                if ($exists) {
                    throw ValidationException::withMessages(['roles' => 'Ya existe un coordinador general activo.']);
                }
            }
            // Identity changes are validated for conflicts even when this service is called from CLI.
            $duplicate = User::when(! $isNew, fn ($q) => $q->where('id', '!=', $user->id))->where(function ($q) use ($data) {
                $q->where('email', $data['email'])->orWhere(fn ($q) => $q->where('identification_type', $data['identification_type'])->where('identification_number', $data['identification_number']));
            })->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['email' => 'El correo o la identificación ya están registrados.']);
            }
            $user->fill(array_intersect_key($data, array_flip(['name', 'identification_type', 'identification_number', 'email'])));
            $fields = array_keys($user->getDirty());
            if ($user->isDirty('email')) {
                if (! $isNew) {
                    DB::table('password_reset_tokens')->where('email', $user->getOriginal('email'))->delete();
                }
                $user->email_verified_at = null;
            }
            $user->status = $data['status'];
            if ($isNew) {
                $user->password = Hash::make($bootstrapPassword ?? $data['identification_number']);
                $user->must_change_password = true;
                $user->created_by = $actor?->id;
            }
            $user->save();
            if (in_array('tutor', $newRoles, true)) {
                Teacher::firstOrCreate(['user_id' => $user->id], ['created_by' => $actor?->id ?? $user->id, 'status' => 'active']);
            }
            $this->audit->record($actor ?? $user, $isNew ? ($actor ? 'user.created' : 'admin.bootstrapped') : 'user.updated', $user->id, ['fields' => $fields]);
            foreach (Role::orderBy('id')->get() as $role) {
                $pivot = DB::table('role_user')->where('role_id', $role->id)->where('user_id', $user->id)->first();
                $wanted = in_array($role->code, $newRoles, true);
                if ($wanted && (! $pivot || $pivot->revoked_at !== null)) {
                    $values = ['assigned_by' => $actor?->id ?? $user->id, 'assigned_at' => now('UTC'), 'revoked_at' => null, 'reason' => $data['reason'] ?? null];
                    if ($pivot) {
                        DB::table('role_user')->where('id', $pivot->id)->update($values);
                    } else {
                        DB::table('role_user')->insert($values + ['role_id' => $role->id, 'user_id' => $user->id, 'created_at' => now('UTC')]);
                    }
                    $this->audit->record($actor ?? $user, $pivot ? 'role.reactivated' : 'role.assigned', $user->id, ['role' => $role->code], $data['reason'] ?? null);
                } elseif (! $wanted && $pivot && $pivot->revoked_at === null) {
                    DB::table('role_user')->where('id', $pivot->id)->update(['revoked_at' => now('UTC'), 'reason' => $data['reason'] ?? null]);
                    $this->audit->record($actor, 'role.revoked', $user->id, ['role' => $role->code], $data['reason'] ?? null);
                }
            }
            if (! $isNew && $oldStatus !== $user->status) {
                $this->audit->record($actor, 'user.status_changed', $user->id, ['status_before' => $oldStatus, 'status_after' => $user->status], $data['reason'] ?? null);
            }
            if (! $isNew && ($oldStatus !== $user->status || $oldRoles !== $newRoles || $fields !== [])) {
                $user->remember_token = Str::random(60);
                $user->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            return $user->load('roles');
        }, 3);
    }

    public function ensureStudent(User $actor, User $subject): User
    {
        $roles = $subject->roles()->pluck('code')->all();
        if (in_array('student', $roles, true)) {
            return $subject;
        }

        return $this->save($actor, $subject, [
            'name' => $subject->name, 'identification_type' => $subject->identification_type,
            'identification_number' => $subject->identification_number, 'email' => $subject->email,
            'status' => $subject->status, 'roles' => array_values(array_unique([...$roles, 'student'])),
            'reason' => 'Incorporación mediante importación académica',
        ]);
    }
}
