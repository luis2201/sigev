<?php

namespace App\Services;

use App\Models\AcademicPeriod;
use App\Models\AttendanceInterval;
use App\Models\HostInstitution;
use App\Models\InstitutionalTutor;
use App\Models\InstitutionArea;
use App\Models\Participation;
use App\Models\ParticipationConfiguration;
use App\Models\PlacementConfiguration;
use App\Models\PlacementGroup;
use App\Models\Project;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\TutorAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlacementManager
{
    public function __construct(private AuditLogger $audit) {}

    /** @return array{fingerprint:string,students:array<int,array<string,mixed>>,conflicts:bool,updated:int} */
    public function review(User $actor, array $data, ?string $expectedFingerprint = null, ?string $expectedRevision = null): array
    {
        return DB::transaction(function () use ($actor, $data, $expectedFingerprint, $expectedRevision) {
            Role::orderBy('id')->lockForUpdate()->get();
            abort_unless($actor->fresh()->hasRole('coordinator'), 403);
            $period = AcademicPeriod::lockForUpdate()->findOrFail($data['academic_period_id']);
            $this->check($period->status !== 'closed', 'academic_period_id', 'El período está cerrado.');
            $group = null;
            $query = Participation::with(['student.user.roles', 'career', 'currentConfiguration.configuration.intervals', 'currentTutor'])->where('academic_period_id', $period->id);
            if (! empty($data['placement_group_id'])) {
                $group = PlacementGroup::lockForUpdate()->findOrFail($data['placement_group_id']);
                $this->check($group->academic_period_id === $period->id && $group->status === 'active', 'placement_group_id', 'Seleccione un grupo activo del mismo período.');
                $query->whereIn('id', $group->memberships()->whereNull('valid_until')->select('participation_id'));
            } else {
                $query->whereKey($data['participation_id']);
            }
            $students = $query->orderBy('id')->lockForUpdate()->get();
            $this->check($students->isNotEmpty(), 'participation_id', 'No existen estudiantes elegibles en el contexto seleccionado.');
            if ($expectedRevision !== null) {
                $this->check(hash_equals($expectedRevision, $this->editingRevision($students, $group)), 'edit_token', 'La asignación o los integrantes cambiaron mientras editaba. Revise la configuración actual antes de guardar.');
                if ($group) {
                    $selected = array_map('intval', $data['selected_participation_ids'] ?? []);
                    $this->check($selected !== [] && array_diff($selected, $students->modelKeys()) === [], 'selected_participation_ids', 'Seleccione únicamente integrantes vigentes de este grupo.');
                    $students = $students->whereIn('id', $selected);
                }
            }

            $project = Project::lockForUpdate()->findOrFail($data['project_id']);
            $this->check($project->academic_period_id === $period->id && $project->status === 'active', 'project_id', 'Seleccione un proyecto activo del mismo período.');
            $institution = HostInstitution::lockForUpdate()->findOrFail($data['host_institution_id']);
            $area = InstitutionArea::lockForUpdate()->findOrFail($data['institution_area_id']);
            $institutionalTutor = InstitutionalTutor::lockForUpdate()->findOrFail($data['institutional_tutor_id']);
            $this->check($institution->status === 'active', 'host_institution_id', 'La institución está inactiva.');
            $this->check($area->host_institution_id === $institution->id && $area->status === 'active', 'institution_area_id', 'El área debe estar activa y pertenecer a la institución.');
            $this->check($institutionalTutor->host_institution_id === $institution->id && (! $institutionalTutor->institution_area_id || $institutionalTutor->institution_area_id === $area->id) && $institutionalTutor->status === 'active', 'institutional_tutor_id', 'El tutor institucional no corresponde a la institución o al área, o está inactivo.');
            $teacher = Teacher::lockForUpdate()->findOrFail($data['teacher_id']);
            $teacherUser = User::lockForUpdate()->findOrFail($teacher->user_id);
            $this->check($teacher->status === 'active' && $teacherUser->hasRole('tutor'), 'teacher_id', 'El docente debe tener perfil activo, cuenta activa y rol de tutor vigente.');
            $this->check($data['practice_starts_on'] >= $period->starts_on->toDateString() && $data['practice_ends_on'] <= $period->ends_on->toDateString(), 'practice_starts_on', 'Las fechas de práctica deben estar dentro del período.');
            $this->validateIntervals($data);
            $minutes = (float) $data['required_hours'] * 60;
            $this->check($minutes > 0 && abs($minutes - round($minutes)) < 0.00001, 'required_hours', 'Las horas requeridas deben corresponder a minutos completos y ser positivas.');
            $rows = [];
            $state = [$group?->getRawOriginal('updated_at'), $group?->memberships()->whereNull('valid_until')->orderBy('id')->pluck('id')->all(), $project->getRawOriginal('updated_at'), $institution->getRawOriginal('updated_at'), $area->getRawOriginal('updated_at'), $institutionalTutor->getRawOriginal('updated_at'), $teacher->getRawOriginal('updated_at'), $teacherUser->getRawOriginal('updated_at')];
            $currents = [];
            foreach ($students as $participation) {
                $this->check($participation->status === 'active' && $participation->student->status === 'active' && $participation->student->user->hasRole('student'), 'participation_id', 'Todos los estudiantes deben tener cuenta, rol, perfil y participación activos.');
                $this->check(! $project->career_id || $project->career_id === $participation->career_id, 'project_id', 'La carrera de un integrante no corresponde al proyecto.');
                if ($participation->project_id && $participation->project_id !== $project->id) {
                    $hasAttendance = Schema::hasTable('attendance_days') && DB::table('attendance_days')->where('participation_id', $participation->id)->exists();
                    $this->check(! $hasAttendance, 'project_id', 'No se puede cambiar el proyecto de una participación con jornadas registradas.');
                }
                $current = $participation->currentConfiguration;
                $tutor = $participation->currentTutor;
                $conflict = (bool) ($current || $tutor || ($participation->project_id && $participation->project_id !== $project->id));
                $rows[] = ['id' => $participation->id, 'name' => $participation->student->user->name, 'career' => $participation->career->name, 'conflict' => $conflict];
                $state[] = [$participation->id, $participation->project_id, $participation->getRawOriginal('updated_at'), $current?->id, $tutor?->id];
                $currents[$participation->id] = [$current, $tutor];
            }
            $fingerprint = hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
            $conflicts = collect($rows)->contains('conflict', true);
            $updated = 0;
            if ($expectedFingerprint !== null || $expectedRevision !== null) {
                $this->check($expectedFingerprint === null || hash_equals($expectedFingerprint, $fingerprint), 'confirmation', 'Los datos cambiaron después de la vista previa. Revise nuevamente la asignación.');
                $this->check(! $conflicts || ! empty($data['replace_existing']), 'replace_existing', 'Existen asignaciones vigentes. Autorice expresamente su reemplazo y genere otra vista previa.');
                foreach ($students as $participation) {
                    [$current, $tutor] = $currents[$participation->id];
                    if ($expectedRevision !== null && $current && (! $group || $current->configuration->placement_group_id === $group->id) && $this->configurationSignature($participation) === $this->valuesSignature($data)) {
                        continue;
                    }
                    if (Schema::hasTable('attendance_intervals')) {
                        $this->check(! AttendanceInterval::where('participation_id', $participation->id)->where('mark_status', 'open')->exists(), 'intervals', 'El estudiante tiene una asistencia abierta. Espere su salida o cierre provisional antes de cambiar la configuración.');
                    }
                    $updated++;
                    $this->apply($actor, $participation, $current, $tutor, $data, $project, $institution, $area, $institutionalTutor, $group, (int) round($minutes));
                }
                if ($updated > 0) {
                    $this->audit->record($actor, $group ? 'placement.group_applied' : 'placement.individual_applied', $group?->id ?? $students->first()->id, reason: $data['reason'], entityType: $group ? 'placement_groups' : 'participations', periodId: $period->id);
                }
            }

            return ['fingerprint' => $fingerprint, 'students' => $rows, 'conflicts' => $conflicts, 'updated' => $updated];
        }, 3);
    }

    /** @return array{defaults:array<string,mixed>,revision:string,roster:array<int,array<string,mixed>>,target_name:string,period_name:string} */
    public function editor(User $actor, Participation|PlacementGroup $target): array
    {
        return DB::transaction(function () use ($actor, $target): array {
            Role::orderBy('id')->lockForUpdate()->get();
            abort_unless($actor->fresh()->hasRole('coordinator'), 403);
            $period = AcademicPeriod::lockForUpdate()->findOrFail($target->academic_period_id);
            $this->check($period->status !== 'closed', 'academic_period_id', 'El período está cerrado y no admite cambios de horario.');
            $group = $target instanceof PlacementGroup ? PlacementGroup::findOrFail($target->id) : null;
            $this->check(! $group || $group->status === 'active', 'placement_group_id', 'El grupo está inactivo.');
            $query = Participation::with(['student.user', 'currentConfiguration.configuration.intervals', 'currentTutor.teacher.user'])->where('academic_period_id', $period->id);
            if ($group) {
                $query->whereIn('id', $group->memberships()->whereNull('valid_until')->select('participation_id'));
            } else {
                $query->whereKey($target->id);
            }
            $students = $query->orderBy('id')->lockForUpdate()->get();
            $source = $students->filter(fn (Participation $p) => $p->currentConfiguration && (! $group || $p->currentConfiguration->configuration->placement_group_id === $group->id))
                ->sortByDesc(fn (Participation $p) => $p->currentConfiguration->id)->first();
            $defaults = $source ? $this->formValues($source) : [];
            $signature = $source ? $this->configurationSignature($source) : null;
            $roster = [];
            foreach ($students as $p) {
                $configuration = $p->currentConfiguration?->configuration;
                $selected = $configuration && $configuration->placement_group_id === $group?->id && $this->configurationSignature($p) === $signature;
                $roster[] = [
                    'id' => $p->id, 'name' => $p->student->user->name, 'selected' => $selected,
                    'type' => ! $configuration ? 'Sin asignación' : ($selected ? 'Configuración del grupo' : 'Configuración diferente / individual'),
                    'institution' => $configuration?->institution_name_snapshot ?? '',
                    'teacher' => $p->currentTutor?->teacher->user->name ?? 'Pendiente',
                    'hours' => $configuration ? $configuration->required_minutes / 60 : null,
                    'schedule' => $configuration?->intervals->map(fn ($interval) => [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'][$interval->weekday].' '.substr($interval->starts_at, 0, 5).'–'.substr($interval->ends_at, 0, 5))->implode(' · ') ?? '',
                ];
            }
            $defaults['selected_participation_ids'] = array_slice(array_column(array_filter($roster, fn ($row) => $row['selected']), 'id'), 0, 500);
            $defaults['reason'] = 'Actualización de horario y asignación';

            return ['defaults' => $defaults, 'revision' => $this->editingRevision($students, $group), 'roster' => $roster, 'target_name' => $group?->name ?? $students->first()?->student->user->name ?? '', 'period_name' => $period->name];
        }, 3);
    }

    /** @return array<string,mixed> */
    private function formValues(Participation $participation): array
    {
        $configuration = $participation->currentConfiguration->configuration;

        return $configuration->only(['project_id', 'host_institution_id', 'institution_area_id', 'institutional_tutor_id']) + [
            'teacher_id' => $participation->currentTutor?->teacher_id,
            'practice_starts_on' => $configuration->practice_starts_on->toDateString(),
            'practice_ends_on' => $configuration->practice_ends_on->toDateString(),
            'required_hours' => $configuration->required_minutes / 60,
            'intervals' => $configuration->intervals->sortBy(fn ($interval) => $interval->weekday.'-'.$interval->starts_at.'-'.$interval->valid_from->toDateString())->map(fn ($interval) => [
                'weekday' => $interval->weekday, 'starts_at' => substr($interval->starts_at, 0, 5), 'ends_at' => substr($interval->ends_at, 0, 5),
                'valid_from' => $interval->valid_from->toDateString(), 'valid_until' => $interval->valid_until?->toDateString(),
            ])->values()->all(),
        ];
    }

    private function configurationSignature(Participation $participation): string
    {
        return $this->valuesSignature($this->formValues($participation));
    }

    private function valuesSignature(array $data): string
    {
        $values = [];
        foreach (['project_id', 'host_institution_id', 'institution_area_id', 'institutional_tutor_id', 'teacher_id'] as $field) {
            $values[$field] = (int) ($data[$field] ?? 0);
        }
        $values['minutes'] = (int) round((float) $data['required_hours'] * 60);
        $values['from'] = $data['practice_starts_on'];
        $values['until'] = $data['practice_ends_on'];
        $values['intervals'] = array_map(fn ($interval) => [(int) $interval['weekday'], $interval['starts_at'], $interval['ends_at'], $interval['valid_from'], $interval['valid_until'] ?? null], $data['intervals']);
        sort($values['intervals']);

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function editingRevision(Collection $students, ?PlacementGroup $group): string
    {
        $state = [$group?->id, $group?->getRawOriginal('updated_at'), $group?->memberships()->whereNull('valid_until')->orderBy('id')->pluck('id')->all()];
        foreach ($students as $p) {
            $state[] = [$p->id, $p->project_id, $p->getRawOriginal('updated_at'), $p->currentConfiguration?->id, $p->currentTutor?->id];
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function validateIntervals(array $data): void
    {
        foreach ($data['intervals'] as $i => $interval) {
            $this->check($interval['starts_at'] < $interval['ends_at'], 'intervals', 'Cada intervalo debe terminar después de su inicio y dentro del mismo día.');
            $until = $interval['valid_until'] ?? $data['practice_ends_on'];
            $this->check($interval['valid_from'] >= $data['practice_starts_on'] && $interval['valid_from'] <= $data['practice_ends_on'] && $until <= $data['practice_ends_on'] && (empty($interval['valid_until']) || $until > $interval['valid_from']), 'intervals', 'La vigencia de cada intervalo debe estar dentro de la práctica y finalizar después de su inicio.');
            foreach (array_slice($data['intervals'], 0, $i) as $other) {
                $overlap = (int) $other['weekday'] === (int) $interval['weekday'] && $other['starts_at'] < $interval['ends_at'] && $interval['starts_at'] < $other['ends_at'] && $other['valid_from'] <= $until && $interval['valid_from'] <= ($other['valid_until'] ?? $data['practice_ends_on']);
                $this->check(! $overlap, 'intervals', 'Existen intervalos superpuestos en el mismo día y vigencia.');
            }
        }
    }

    private function apply(User $actor, Participation $participation, ?ParticipationConfiguration $current, ?TutorAssignment $tutor, array $data, Project $project, HostInstitution $institution, InstitutionArea $area, InstitutionalTutor $institutionalTutor, ?PlacementGroup $group, int $minutes): void
    {
        $instant = now('UTC');
        foreach ([$current, $tutor] as $previous) {
            if ($previous && $instant->lessThanOrEqualTo($previous->valid_from)) {
                $instant = $previous->valid_from->addMicrosecond()->toMutable();
            }
        }
        $previousConfiguration = $current?->configuration;
        if ($current) {
            $current->update(['valid_until' => $instant]);
            $previousConfiguration->update(['valid_until' => $instant]);
        }
        $configuration = PlacementConfiguration::create([
            'academic_period_id' => $participation->academic_period_id, 'placement_group_id' => $group?->id,
            'host_institution_id' => $institution->id, 'institution_area_id' => $area->id,
            'institutional_tutor_id' => $institutionalTutor->id, 'institutional_tutor_name' => $institutionalTutor->name,
            'project_id' => $project->id, 'project_name_snapshot' => $project->name,
            'configuration_key' => $previousConfiguration?->configuration_key ?? (string) Str::uuid(),
            'version_number' => ($previousConfiguration?->version_number ?? 0) + 1,
            'institution_name_snapshot' => $institution->name, 'address_snapshot' => $institution->address, 'area_name_snapshot' => $area->name,
            'practice_starts_on' => $data['practice_starts_on'], 'practice_ends_on' => $data['practice_ends_on'], 'required_minutes' => $minutes,
            'valid_from' => $instant, 'created_by' => $actor->id, 'reason' => $data['reason'],
        ]);
        foreach ($data['intervals'] as $interval) {
            $configuration->intervals()->create($interval + ['status' => 'active']);
        }
        ParticipationConfiguration::create(['participation_id' => $participation->id, 'placement_configuration_id' => $configuration->id, 'academic_period_id' => $participation->academic_period_id, 'valid_from' => $instant, 'assigned_by' => $actor->id, 'reason' => $data['reason']]);
        if (! $tutor || $tutor->teacher_id !== (int) $data['teacher_id']) {
            if ($tutor) {
                $tutor->update(['valid_until' => $instant]);
            }
            $assignment = TutorAssignment::create(['participation_id' => $participation->id, 'teacher_id' => $data['teacher_id'], 'valid_from' => $instant, 'assigned_by' => $actor->id, 'reason' => $data['reason']]);
            $this->audit->record($actor, 'placement.tutor_assigned', $assignment->id, reason: $data['reason'], entityType: 'tutor_assignments', periodId: $participation->academic_period_id);
        }
        $participation->update(['project_id' => $project->id]);
        $this->audit->record($actor, 'placement.version_created', $configuration->id, ['fields' => ['project_id', 'required_minutes', 'schedule_intervals', 'institutional_tutor_id']], $data['reason'], 'placement_configurations', $participation->academic_period_id);
    }

    private function check(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
