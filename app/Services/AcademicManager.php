<?php

namespace App\Services;

use App\Models\AcademicPeriod;
use App\Models\Career;
use App\Models\PlacementConfiguration;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AcademicManager
{
    public function __construct(private AuditLogger $audit) {}

    public function career(User $actor, ?Career $career, array $data): Career
    {
        return DB::transaction(function () use ($actor, $career, $data) {
            Role::orderBy('id')->lockForUpdate()->get();
            abort_unless($actor->fresh()->hasRole('admin'), 403);
            $record = $career ? Career::lockForUpdate()->findOrFail($career->id) : new Career;
            $record->fill($data);
            $fields = array_keys($record->getDirty());
            $record->save();
            $this->audit->record($actor, 'career.saved', $record->id, ['fields' => $fields], entityType: 'careers');

            return $record;
        }, 3);
    }

    public function period(User $actor, ?AcademicPeriod $period, array $data): AcademicPeriod
    {
        return DB::transaction(function () use ($actor, $period, $data) {
            Role::orderBy('id')->lockForUpdate()->get();
            abort_unless($actor->fresh()->hasRole('admin'), 403);
            $record = $period ? AcademicPeriod::lockForUpdate()->findOrFail($period->id) : new AcademicPeriod;
            if ($record->exists && $record->status === 'closed') {
                throw ValidationException::withMessages(['status' => 'Un período cerrado conserva su historial y no admite modificaciones ordinarias.']);
            }
            if ($data['status'] === 'closed' && $record->exists && $record->imports()->whereIn('status', ['pending', 'validating', 'processing'])->exists()) {
                throw ValidationException::withMessages(['status' => 'No puede cerrar el período mientras existan importaciones pendientes. Confirme o cancele sus vistas previas.']);
            }
            if ($record->exists && Schema::hasTable('placement_configurations') && PlacementConfiguration::where('academic_period_id', $record->id)->where(fn ($query) => $query->where('practice_starts_on', '<', $data['starts_on'])->orWhere('practice_ends_on', '>', $data['ends_on']))->exists()) {
                throw ValidationException::withMessages(['starts_on' => 'Las fechas del período deben conservar el rango de las prácticas vigentes e históricas.']);
            }
            $record->fill($data);
            if (! $record->exists) {
                $record->created_by = $actor->id;
            }
            if ($data['status'] === 'closed') {
                $record->closed_at = now('UTC');
                $record->closed_by = $actor->id;
            }
            $fields = array_keys($record->getDirty());
            $record->save();
            $this->audit->record($actor, 'period.saved', $record->id, ['fields' => $fields], entityType: 'academic_periods', periodId: $record->id);

            return $record;
        }, 3);
    }

    public function teacher(User $actor, User $account, array $data): Teacher
    {
        return DB::transaction(function () use ($actor, $account, $data) {
            Role::orderBy('id')->lockForUpdate()->get();
            $actor = $actor->fresh();
            abort_unless($actor->hasRole('admin') || $actor->hasRole('coordinator') || ($actor->id === $account->id && $actor->hasRole('tutor')), 403);
            $account = User::lockForUpdate()->findOrFail($account->id);
            abort_unless($account->teacher()->exists() || $account->roles()->where('code', 'tutor')->exists(), 404);
            $record = Teacher::firstOrNew(['user_id' => $account->id]);
            $record->academic_title = $data['academic_title'] ?? null;
            if ($actor->hasRole('admin') || $actor->hasRole('coordinator')) {
                $record->status = $data['status'];
            } elseif (! $record->exists) {
                $record->status = 'active';
            }
            if (! $record->exists) {
                $record->created_by = $actor->id;
            }
            $record->save();
            $this->audit->record($actor, 'teacher.profile_saved', $record->id, ['fields' => ['academic_title', 'status']], entityType: 'teachers');

            return $record;
        }, 3);
    }
}
