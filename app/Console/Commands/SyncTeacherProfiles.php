<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncTeacherProfiles extends Command
{
    protected $signature = 'sigev:sync-teacher-profiles';

    protected $description = 'Completa perfiles académicos faltantes de cuentas activas con rol tutor, sin cambiar sus credenciales';

    public function handle(AuditLogger $audit): int
    {
        $count = DB::transaction(function () use ($audit) {
            Role::orderBy('id')->lockForUpdate()->get();
            $count = 0;
            $accounts = User::where('status', 'active')->whereHas('roles', fn ($query) => $query->where('code', 'tutor'))->whereDoesntHave('teacher')->orderBy('id')->lockForUpdate()->get();
            foreach ($accounts as $account) {
                $teacher = Teacher::firstOrCreate(['user_id' => $account->id], ['status' => 'active', 'created_by' => $account->id]);
                if ($teacher->wasRecentlyCreated) {
                    $count++;
                    $audit->record(null, 'teacher.profile_linked', $teacher->id, ['fields' => ['user_id', 'status']], entityType: 'teachers');
                }
            }

            return $count;
        }, 3);
        $this->info('Perfiles académicos completados: '.$count);

        return self::SUCCESS;
    }
}
