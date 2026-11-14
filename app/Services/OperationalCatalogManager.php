<?php

namespace App\Services;

use App\Models\AcademicPeriod;
use App\Models\GroupMembership;
use App\Models\HostInstitution;
use App\Models\InstitutionalTutor;
use App\Models\InstitutionArea;
use App\Models\Participation;
use App\Models\PlacementGroup;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OperationalCatalogManager
{
    public const CATALOGS = [
        'projects' => [Project::class, 'Proyectos de vinculación'],
        'institutions' => [HostInstitution::class, 'Instituciones receptoras'],
        'areas' => [InstitutionArea::class, 'Áreas institucionales'],
        'institutional-tutors' => [InstitutionalTutor::class, 'Tutores institucionales'],
        'groups' => [PlacementGroup::class, 'Grupos de vinculación'],
    ];

    public function __construct(private AuditLogger $audit) {}

    public function save(User $actor, string $kind, ?int $id, array $data): Model
    {
        try {
            return DB::transaction(function () use ($actor, $kind, $id, $data) {
                Role::orderBy('id')->lockForUpdate()->get();
                abort_unless($actor->fresh()->hasRole('coordinator'), 403);
                $class = self::CATALOGS[$kind][0];
                $periodId = $data['academic_period_id'] ?? null;
                if ($periodId) {
                    $period = AcademicPeriod::lockForUpdate()->findOrFail($periodId);
                    $this->check($period->status !== 'closed', 'academic_period_id', 'El período está cerrado.');
                }
                $record = $id ? $class::lockForUpdate()->findOrFail($id) : new $class;
                if ($id) {
                    $this->check(($data['revision'] ?? '') === $record->getRawOriginal('updated_at'), 'revision', 'El registro cambió. Recargue el formulario.');
                    if (in_array($kind, ['projects', 'groups'], true)) {
                        $this->check((int) $record->academic_period_id === (int) $periodId, 'academic_period_id', 'No se puede trasladar el registro a otro período.');
                    }
                    if ($kind === 'projects' && $record->participations()->exists()) {
                        $this->check((int) $record->career_id === (int) ($data['career_id'] ?? 0), 'career_id', 'La carrera de un proyecto con estudiantes conserva su historial.');
                    }
                    if (in_array($kind, ['areas', 'institutional-tutors'], true)) {
                        $this->check((int) $record->host_institution_id === (int) $data['host_institution_id'], 'host_institution_id', 'No se puede trasladar este registro a otra institución.');
                    }
                }
                unset($data['revision']);
                if (in_array($kind, ['institutions', 'institutional-tutors'], true)) {
                    $data['normalized_name'] = Str::lower(preg_replace('/[^\pL\pN]+/u', '', Str::ascii($data['name'])));
                    $this->check($data['normalized_name'] !== '', 'name', 'Ingrese un nombre válido.');
                }
                if (isset($data['host_institution_id'])) {
                    $institution = HostInstitution::lockForUpdate()->findOrFail($data['host_institution_id']);
                    $this->check($institution->status === 'active' || $data['status'] === 'inactive', 'host_institution_id', 'La institución está inactiva.');
                }
                if (! empty($data['institution_area_id'])) {
                    $area = InstitutionArea::lockForUpdate()->findOrFail($data['institution_area_id']);
                    $this->check($area->host_institution_id === $institution->id && ($area->status === 'active' || $data['status'] === 'inactive'), 'institution_area_id', 'Seleccione un área activa de la institución.');
                }
                if ($kind === 'groups' && ! $id) {
                    $data['created_by'] = $actor->id;
                }
                $record->fill($data);
                $fields = array_keys($record->getDirty());
                $record->save();
                $this->audit->record($actor, 'operational.'.$kind.'.saved', $record->id, ['fields' => $fields], entityType: $record->getTable(), periodId: $periodId);

                return $record;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['name' => 'Ya existe un registro con ese código o nombre en este contexto.']);
        }
    }

    public function membership(User $actor, PlacementGroup $group, array $data): int
    {
        return DB::transaction(function () use ($actor, $group, $data) {
            Role::orderBy('id')->lockForUpdate()->get();
            abort_unless($actor->fresh()->hasRole('coordinator'), 403);
            $period = AcademicPeriod::lockForUpdate()->findOrFail($group->academic_period_id);
            $this->check($period->status !== 'closed', 'academic_period_id', 'El período está cerrado.');
            $ids = $data['participation_ids'] ?? [$data['participation_id']];
            $field = isset($data['participation_ids']) ? 'participation_ids' : 'participation_id';
            $participations = Participation::with('student.user.roles')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $this->check($participations->count() === count($ids), $field, 'La selección contiene participaciones inexistentes o repetidas.');
            $group = PlacementGroup::lockForUpdate()->findOrFail($group->id);
            $this->check($group->status === 'active', $field, 'El grupo está inactivo.');
            $memberships = $group->memberships()->whereNull('valid_until')->whereIn('participation_id', $ids)->lockForUpdate()->get()->keyBy('participation_id');
            $currentGroups = $data['action'] === 'add' ? GroupMembership::with('group')->whereNull('valid_until')->whereIn('participation_id', $ids)->lockForUpdate()->get()->keyBy('participation_id') : collect();
            foreach ($participations as $participation) {
                $this->check($participation->academic_period_id === $group->academic_period_id, $field, 'Seleccione participaciones del período del grupo.');
                if ($data['action'] === 'add') {
                    $this->check($participation->status === 'active' && $participation->student->status === 'active' && $participation->student->user->hasRole('student'), $field, 'Todos los estudiantes seleccionados deben tener cuenta, rol, perfil y participación activos.');
                    $existingGroup = $currentGroups->get($participation->id);
                    $this->check(! $existingGroup, $field, $participation->student->user->name.' ya pertenece al grupo «'.($existingGroup?->group->name ?? '').'» en este período. Retírelo de ese grupo antes de incorporarlo a otro.');
                } else {
                    $this->check($memberships->has($participation->id), $field, 'Uno de los estudiantes seleccionados ya no pertenece al grupo. Actualice la lista y revise su selección.');
                }
            }
            foreach ($participations as $participation) {
                if ($data['action'] === 'add') {
                    $membership = $group->memberships()->create(['participation_id' => $participation->id, 'academic_period_id' => $period->id, 'valid_from' => now('UTC'), 'assigned_by' => $actor->id, 'reason' => $data['reason']]);
                } else {
                    $membership = $memberships->get($participation->id);
                    $membership->update(['valid_until' => now('UTC')->max($membership->valid_from->addMicrosecond())]);
                }
                $this->audit->record($actor, 'group.member.'.$data['action'], $membership->id, reason: $data['reason'], entityType: 'group_memberships', periodId: $period->id);
            }

            return $participations->count();
        }, 3);
    }

    private function check(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
