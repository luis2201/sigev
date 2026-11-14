<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityEvidence;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\AttendanceIncident;
use App\Models\AttendanceInterval;
use App\Models\AttendanceReview;
use App\Models\Participation;
use App\Models\ParticipationConfiguration;
use App\Models\PrivateFile;
use App\Models\Role;
use App\Models\ScheduleInterval;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AttendanceManager
{
    public function __construct(private AttendanceAccess $access, private AttendanceCalculator $calculator, private AuditLogger $audit) {}

    private function clock(): CarbonImmutable
    {
        return CarbonImmutable::instance(now('UTC'))->startOfSecond();
    }

    private function check(bool $condition, string $message, string $field = 'attendance'): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function locked(?User $actor, int $participationId, Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $participationId, $operation): mixed {
            Role::orderBy('id')->lockForUpdate()->get();
            $actor = $actor?->fresh();
            if ($actor) {
                abort_unless($actor->status === 'active', 403);
            }
            $base = Participation::findOrFail($participationId);
            DB::table('academic_periods')->where('id', $base->academic_period_id)->lockForUpdate()->first();
            Student::whereKey($base->student_id)->lockForUpdate()->firstOrFail();
            $participation = Participation::with(['student.user', 'period', 'project'])->lockForUpdate()->findOrFail($participationId);

            return $operation($actor, $participation, $this->clock());
        }, 1);
    }

    private function periodOpen(Participation $p, CarbonInterface $now): void
    {
        $date = $now->copy()->timezone(config('attendance.timezone'))->toDateString();
        $this->check($p->period->status === 'active', 'El período debe estar activo; un período cerrado o en preparación no permite registros ordinarios.');
        $this->check($date >= $p->period->starts_on->toDateString() && $date <= $p->period->ends_on->toDateString(), 'La fecha actual está fuera del período académico.');
    }

    /** @return array{application:ParticipationConfiguration,program:array<int,array{schedule:ScheduleInterval,start:CarbonImmutable,end:CarbonImmutable}>} */
    private function context(Participation $p, CarbonImmutable $now): array
    {
        $this->periodOpen($p, $now);
        $this->check($p->status === 'active' && $p->student->status === 'active', 'La participación o el perfil del estudiante no están activos. Solicite revisión al coordinador.');
        $this->check($p->project && $p->project->academic_period_id === $p->academic_period_id && $p->project->status === 'active' && (! $p->project->career_id || $p->project->career_id === $p->career_id), 'Falta un proyecto activo y compatible con su período y carrera. Solicite la asignación al coordinador.');
        $application = ParticipationConfiguration::with(['configuration.intervals', 'configuration.institution', 'configuration.area'])->where('participation_id', $p->id)->where('valid_from', '<=', $now)->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', $now))->first();
        $this->check((bool) $application, 'No tiene una configuración de vinculación vigente. Solicítela al coordinador.');
        $config = $application->configuration;
        $this->check($config->project_id === $p->project_id && $config->academic_period_id === $p->academic_period_id && $config->required_minutes > 0, 'La configuración de proyecto u horas requeridas debe ser revisada por el coordinador.');
        $this->check($config->valid_from->lessThanOrEqualTo($now) && (! $config->valid_until || $config->valid_until->greaterThan($now)), 'La configuración de horario no está vigente.');
        $this->check($config->institution->status === 'active' && $config->area->status === 'active' && $config->area->host_institution_id === $config->host_institution_id, 'La institución o el área no están habilitadas. Solicite revisión al coordinador.');
        $tutor = $p->tutorAssignments()->with('teacher.user.roles')->where('valid_from', '<=', $now)->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', $now))->first();
        $this->check($tutor && $tutor->teacher->status === 'active' && $tutor->teacher->user->hasRole('tutor') && $tutor->teacher->user_id !== $p->student->user_id, 'Falta un docente tutor habilitado para supervisar su participación. Solicítelo al coordinador.');
        $date = $now->timezone(config('attendance.timezone'))->toDateString();
        $this->check($date >= $config->practice_starts_on->toDateString() && $date <= $config->practice_ends_on->toDateString(), 'Hoy está fuera de las fechas de práctica asignadas.');
        $program = [];
        foreach ($config->intervals as $schedule) {
            if (! $this->scheduledOn($schedule, $date)) {
                continue;
            }
            $start = CarbonImmutable::parse($date.' '.$schedule->starts_at, config('attendance.timezone'))->utc();
            $end = CarbonImmutable::parse($date.' '.$schedule->ends_at, config('attendance.timezone'))->utc();
            foreach ([$config->valid_from, $application->valid_from] as $from) {
                $boundary = $from->timestamp + ((int) $from->format('u') > 0 ? 1 : 0);
                $start = $start->max(CarbonImmutable::createFromTimestampUTC($boundary));
            }
            foreach ([$config->valid_until, $application->valid_until] as $until) {
                if ($until) {
                    $end = $end->min(CarbonImmutable::createFromTimestampUTC($until->timestamp));
                }
            }
            if ($end->greaterThan($start)) {
                $program[] = compact('schedule', 'start', 'end');
            }
        }
        usort($program, fn ($a, $b) => $a['start']->timestamp <=> $b['start']->timestamp);
        $this->check($program !== [], 'No tiene un horario de vinculación autorizado para hoy.');

        return ['application' => $application, 'program' => $program];
    }

    private function scheduledOn(ScheduleInterval $schedule, string $date): bool
    {
        return $schedule->status === 'active' && (int) $schedule->weekday === CarbonImmutable::parse($date, config('attendance.timezone'))->isoWeekday()
            && $schedule->valid_from->toDateString() <= $date && (! $schedule->valid_until || $schedule->valid_until->toDateString() >= $date);
    }

    /** @return array<string,mixed> */
    public function availability(User $actor, Participation $p): array
    {
        abort_unless($this->access->owner($actor, $p), 403);
        $day = AttendanceDay::where('participation_id', $p->id)->where('local_date', $this->clock()->timezone(config('attendance.timezone'))->toDateString())->first();
        try {
            $context = $this->context($p, $this->clock());
            $day = AttendanceDay::where('participation_id', $p->id)->where('local_date', $this->clock()->timezone(config('attendance.timezone'))->toDateString())->first();
            $open = $day?->intervals()->where('mark_status', 'open')->first();
            $used = $day?->intervals()->pluck('schedule_interval_id')->all() ?? [];
            $canEnter = ! $open && (! $day?->current_review_id || $day->currentReview?->decision === 'reopened') && collect($context['program'])->contains(fn ($part) => $part['end']->greaterThan($this->clock()) && ! in_array($part['schedule']->id, $used, true));

            return $context + ['day' => $day, 'open' => $open, 'can_enter' => $canEnter, 'can_exit' => (bool) $open, 'message' => $canEnter || $open ? null : 'Los tramos de hoy ya finalizaron o fueron registrados. Consulte el detalle de su jornada.'];
        } catch (ValidationException $exception) {
            return ['day' => $day, 'program' => [], 'can_enter' => false, 'can_exit' => $p->period->status === 'active' && (bool) $day?->intervals()->where('mark_status', 'open')->exists(), 'message' => $exception->validator->errors()->first()];
        }
    }

    public function enter(User $actor, Participation $p, string $key): AttendanceDay
    {
        return $this->locked($actor, $p->id, function (User $actor, Participation $p, CarbonImmutable $now) use ($key): AttendanceDay {
            abort_unless($this->access->owner($actor, $p), 403);
            $existing = AttendanceInterval::where('entry_request_key', $key)->first();
            if ($existing) {
                $this->check($existing->participation_id === $p->id, 'La solicitud de entrada ya fue utilizada.');

                return $existing->day;
            }
            $context = $this->context($p, $now);
            $date = $now->timezone(config('attendance.timezone'))->toDateString();
            $day = AttendanceDay::where('participation_id', $p->id)->where('local_date', $date)->lockForUpdate()->first();
            $open = AttendanceInterval::whereHas('participation', fn ($q) => $q->where('student_id', $p->student_id))->where('mark_status', 'open')->first();
            if ($open) {
                $this->check($open->participation_id === $p->id && $open->day->local_date->toDateString() === $date, 'Existe una entrada abierta de otra jornada. Debe cerrarse antes de registrar una nueva.');

                return $open->day;
            }
            $this->check(! $day?->current_review_id || $day->currentReview?->decision === 'reopened', 'La jornada ya tiene una decisión docente; no admite nuevas marcaciones ordinarias.');
            $used = $day?->intervals()->pluck('schedule_interval_id')->all() ?? [];
            $part = collect($context['program'])->first(fn ($part) => $part['end']->greaterThan($now) && ! in_array($part['schedule']->id, $used, true));
            $this->check((bool) $part, 'No quedan tramos habilitados para registrar entrada hoy.');
            $day ??= AttendanceDay::create(['participation_id' => $p->id, 'local_date' => $date, 'status' => 'in_progress', 'revision_number' => 1]);
            AttendanceInterval::create(['attendance_day_id' => $day->id, 'participation_id' => $p->id,
                'participation_configuration_id' => $context['application']->id, 'placement_configuration_id' => $context['application']->placement_configuration_id,
                'schedule_interval_id' => $part['schedule']->id, 'authorized_start_at' => $part['start'], 'authorized_end_at' => $part['end'],
                'actual_entry_at' => $now, 'mark_status' => 'open', 'entry_request_key' => $key]);
            $day->closed_at = null;
            $this->mutated($day, $actor, 'entry', 'in_progress');
            if ($now->lessThan($part['start'])) {
                $this->incident($day, null, 'mark_error', 'Entrada anticipada: el tiempo anterior al horario autorizado no es acreditable.', $actor);
            }

            return $day;
        });
    }

    public function leave(User $actor, Participation $p, string $key): AttendanceDay
    {
        return $this->locked($actor, $p->id, function (User $actor, Participation $p, CarbonImmutable $now) use ($key): AttendanceDay {
            abort_unless($this->access->owner($actor, $p), 403);
            $previous = AttendanceInterval::where('exit_request_key', $key)->first();
            if ($previous) {
                $this->check($previous->participation_id === $p->id, 'La solicitud de salida ya fue utilizada.');

                return $previous->day;
            }
            $this->periodOpen($p, $now);
            $day = AttendanceDay::where('participation_id', $p->id)->where('local_date', $now->timezone(config('attendance.timezone'))->toDateString())->lockForUpdate()->first();
            $interval = $day?->intervals()->where('mark_status', 'open')->lockForUpdate()->first();
            $this->check((bool) $interval, 'No existe una entrada abierta para hoy. Consulte si el intervalo ya fue cerrado provisionalmente.');
            $this->check($now->greaterThanOrEqualTo($interval->actual_entry_at), 'La salida no puede ser anterior a la entrada.');
            $this->closeInterval($interval, $now, false, $key);
            $this->mutated($day, $actor, 'exit');
            $this->finalize($day, $actor, $now);

            return $day;
        });
    }

    private function closeInterval(AttendanceInterval $interval, CarbonImmutable $end, bool $automatic, ?string $key = null): void
    {
        $seconds = $this->calculator->eligibleSeconds([[$interval->actual_entry_at->timestamp, $end->timestamp]], [[$interval->authorized_start_at->timestamp, $interval->authorized_end_at->timestamp]]);
        $interval->fill(['mark_status' => $automatic ? 'provisional_closed' : 'manual_closed', 'closure_type' => $automatic ? 'automatic_provisional' : 'manual', 'computable_minutes' => $this->calculator->decimal($this->calculator->minuteUnitsFromSeconds($seconds))]);
        if ($automatic) {
            $interval->provisional_closed_at = $end;
        } else {
            $interval->actual_exit_at = $end;
            $interval->exit_request_key = $key;
        }
        $interval->save();
    }

    public function autoClose(int $intervalId): bool
    {
        $base = AttendanceInterval::findOrFail($intervalId);

        return $this->locked(null, $base->participation_id, function (?User $actor, Participation $p, CarbonImmutable $now) use ($intervalId): bool {
            $interval = AttendanceInterval::with('day')->lockForUpdate()->findOrFail($intervalId);
            $day = AttendanceDay::lockForUpdate()->findOrFail($interval->attendance_day_id);
            if ($interval->mark_status !== 'open' || $interval->authorized_end_at->greaterThan($now)) {
                return false;
            }
            $this->closeInterval($interval, CarbonImmutable::instance($interval->authorized_end_at), true);
            $this->incident($day, $interval, 'missing_exit', 'Salida omitida. Cierre provisional al límite programado; no confirma presencia real hasta esa hora.', null);
            $this->mutated($day, null, 'automatic_close');
            $this->finalize($day, null, $now);

            return true;
        });
    }

    public function finalizeDay(int $dayId): void
    {
        $base = AttendanceDay::findOrFail($dayId);
        $this->locked(null, $base->participation_id, function (?User $actor, Participation $p, CarbonImmutable $now) use ($dayId): void {
            $this->finalize(AttendanceDay::lockForUpdate()->findOrFail($dayId), null, $now);
        });
    }

    public function lastEnd(AttendanceDay $day): ?CarbonImmutable
    {
        $day->loadMissing('intervals.configuration.intervals');
        $last = null;
        $date = $day->local_date->toDateString();
        foreach ($day->intervals as $interval) {
            foreach ($interval->configuration->intervals as $schedule) {
                if ($this->scheduledOn($schedule, $date)) {
                    $end = CarbonImmutable::parse($date.' '.$schedule->ends_at, config('attendance.timezone'))->utc();
                    $last = $last ? $last->max($end) : $end;
                }
            }
            $last = $last ? $last->max($interval->authorized_end_at) : CarbonImmutable::instance($interval->authorized_end_at);
        }

        return $last;
    }

    private function finalize(AttendanceDay $day, ?User $actor, CarbonImmutable $now): void
    {
        if ($day->closed_at || $day->intervals()->where('mark_status', 'open')->exists()) {
            return;
        }
        $last = $this->lastEnd($day->fresh());
        if (! $last || $last->greaterThan($now)) {
            return;
        }
        $hasActivities = Activity::whereHas('interval', fn ($q) => $q->where('attendance_day_id', $day->id))->exists();
        if (! $hasActivities) {
            $this->incident($day, null, 'no_activities', 'La jornada finalizó sin actividades ni evidencias. Requiere revisión expresa del docente.', null);
        }
        $provisional = $day->intervals()->where('mark_status', 'provisional_closed')->exists();
        $day->closed_at = $actor ? $now : $last;
        $this->mutated($day, $actor, 'day_closed', ! $hasActivities || $provisional ? 'observed' : 'pending');
    }

    public function activity(User $actor, AttendanceDay $day, string $description, UploadedFile $photo): Activity
    {
        $path = null;
        try {
            return $this->locked($actor, $day->participation_id, function (User $actor, Participation $p, CarbonImmutable $now) use ($day, $description, $photo, &$path): Activity {
                abort_unless($this->access->owner($actor, $p), 403);
                $this->context($p, $now);
                $day = AttendanceDay::lockForUpdate()->findOrFail($day->id);
                $this->check($day->local_date->toDateString() === $now->timezone(config('attendance.timezone'))->toDateString(), 'Solo puede registrar actividades del día actual.');
                $this->check(! $day->closed_at && ! $day->current_review_id && $day->status === 'in_progress', 'La jornada está cerrada, revisada o bloqueada.');
                $interval = $day->intervals()->where('mark_status', 'open')->lockForUpdate()->first();
                $this->check($interval && $now->lessThan($interval->authorized_end_at), 'Para registrar una actividad debe tener un intervalo abierto dentro de su horario vigente.');
                [$bytes, $mime, $extension] = $this->normalizePhoto($photo);
                $path = 'activity-evidences/'.$now->format('Y/m').'/'.Str::uuid().'.'.$extension;
                $this->check(Storage::disk('local')->put($path, $bytes, ['visibility' => 'private']) && Storage::disk('local')->exists($path), 'No se pudo guardar la fotografía en el almacenamiento privado.', 'photo');
                $file = PrivateFile::create(['disk' => 'local', 'storage_key' => $path, 'mime_type' => $mime, 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'uploaded_by' => $actor->id, 'status' => 'ready']);
                $activity = Activity::create(['attendance_interval_id' => $interval->id, 'description' => trim($description), 'registered_at' => $now, 'created_by' => $actor->id, 'status' => 'complete']);
                ActivityEvidence::create(['activity_id' => $activity->id, 'private_file_id' => $file->id]);
                $this->mutated($day, $actor, 'activity_registered');
                $this->audit->record($actor, 'attendance.photo_stored', $file->id, entityType: 'private_files', periodId: $p->academic_period_id);

                return $activity;
            });
        } catch (Throwable $exception) {
            if ($path) {
                try {
                    Storage::disk('local')->delete($path);
                } catch (Throwable) {
                    Log::warning('No se pudo completar la limpieza de una evidencia fallida.');
                }
            }
            if ($exception instanceof ValidationException || $exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            Log::error('Falló el registro consistente de actividad y fotografía.', ['exception_type' => $exception::class]);
            throw ValidationException::withMessages(['photo' => 'No se pudo registrar la actividad con su fotografía. Intente nuevamente o solicite revisar el almacenamiento privado.']);
        }
    }

    /** @return array{0:string,1:string,2:string} */
    private function normalizePhoto(UploadedFile $photo): array
    {
        $this->check($photo->isValid() && $photo->getSize() <= config('attendance.photo_max_kb') * 1024, 'La fotografía excede el tamaño permitido o no se recibió correctamente.', 'photo');
        $info = @getimagesize($photo->getRealPath());
        $this->check($info && in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) && $info[0] <= config('attendance.photo_max_dimension') && $info[1] <= config('attendance.photo_max_dimension') && config('attendance.photo_max_pixels') >= $info[0] * $info[1], 'Seleccione una fotografía JPEG, PNG o WebP con dimensiones permitidas.', 'photo');
        $image = @imagecreatefromstring(file_get_contents($photo->getRealPath()));
        $this->check($image !== false, 'El contenido de la fotografía no es válido.', 'photo');
        ob_start();
        try {
            $ok = imagejpeg($image, null, 85);
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->check($ok && is_string($bytes) && strlen($bytes) > 0, 'No se pudo procesar la fotografía.', 'photo');

        return [$bytes, 'image/jpeg', 'jpg'];
    }

    public function review(User $actor, AttendanceDay $day, array $data): AttendanceReview
    {
        return $this->locked($actor, $day->participation_id, function (User $actor, Participation $p, CarbonImmutable $now) use ($day, $data): AttendanceReview {
            $day = AttendanceDay::lockForUpdate()->findOrFail($day->id);
            $tutor = $this->access->tutor($actor, $p);
            abort_unless($tutor, 403);
            $existing = AttendanceReview::where('request_key', $data['request_key'])->first();
            if ($existing) {
                $this->check($existing->attendance_day_id === $day->id && $existing->reviewed_by === $actor->id, 'La solicitud ya fue utilizada.');

                return $existing;
            }
            $this->check($p->period->status === 'active', 'El período está cerrado o no está habilitado para revisión académica.');
            $this->check((int) $data['revision_number'] === $day->revision_number, 'La jornada cambió desde que la abrió. Recargue el detalle antes de decidir.');
            $last = $this->lastEnd($day->fresh());
            $this->check($day->closed_at && ! $day->intervals()->where('mark_status', 'open')->exists() && $last && $last->lessThanOrEqualTo($now), 'La jornada no está finalizada o todavía tiene intervalos abiertos o programados pendientes.');
            $decision = $data['decision'];
            $approved = in_array($day->status, ['approved', 'exceptional'], true);
            $this->check(! $approved || $decision === 'reopened', 'Primero debe reabrir la jornada aprobada, indicando el motivo.');
            $this->check($decision !== 'reopened' || $approved, 'Solo se puede reabrir una jornada con aprobación vigente.');
            $intervals = $day->intervals()->with('activities.evidence.file')->get();
            $totals = $this->calculator->totals($intervals);
            $units = $this->calculator->minuteUnitsFromSeconds($totals['eligible_seconds']);
            if (in_array($decision, ['ordinary', 'exceptional'], true)) {
                $this->check($units > 0, 'La jornada no tiene tiempo elegible para acreditar.');
                if ($decision === 'ordinary') {
                    $activities = $intervals->flatMap(fn ($interval) => $interval->activities);
                    $this->check(! $totals['provisional'], 'La salida provisional requiere evaluación excepcional expresa con justificación y respaldo.');
                    $this->check($activities->isNotEmpty() && $activities->every(fn ($activity) => $activity->status === 'complete' && $this->evidenceAvailable($activity->evidence?->file)), 'La aprobación ordinaria requiere actividades completas con fotografía disponible.');
                } else {
                    $this->check(trim($data['circumstance'] ?? '') !== '' && mb_strlen(trim($data['justification'] ?? '')) >= 10 && mb_strlen(trim($data['support_reference'] ?? '')) >= 5, 'La excepción exige circunstancia, justificación y una referencia de respaldo verificable.');
                    $this->incident($day, null, 'other', 'Aprobación excepcional: '.$data['circumstance'], $actor, $data['support_reference']);
                }
            }
            $from = $day->status;
            $revision = $day->revision_number + 1;
            $review = AttendanceReview::create(['attendance_day_id' => $day->id, 'participation_id' => $p->id, 'tutor_assignment_id' => $tutor->id, 'reviewed_by' => $actor->id,
                'sequence_number' => (int) $day->reviews()->max('sequence_number') + 1, 'decision' => $decision,
                'justification' => $decision === 'exceptional' ? 'Circunstancia: '.$data['circumstance']."\n".$data['justification'] : ($data['justification'] ?? null), 'support_reference' => $decision === 'exceptional' ? $data['support_reference'] : null,
                'approved_minutes' => $this->calculator->decimal(in_array($decision, ['ordinary', 'exceptional'], true) ? $units : 0),
                'computable_minutes_snapshot' => $this->calculator->decimal($units), 'day_revision_number' => $revision, 'reviewed_at' => $now, 'request_key' => $data['request_key']]);
            $day->fill(['status' => match ($decision) {
                'ordinary' => 'approved', 'exceptional' => 'exceptional', 'rejected' => 'rejected', default => 'observed'
            }, 'current_review_id' => $review->id, 'revision_number' => $revision])->save();
            $this->event($day, $actor, $from, $data['justification'] ?? null);
            $this->audit->record($actor, 'attendance.review.'.$decision, $review->id, ['fields' => ['current_review_id', 'status']], $data['justification'] ?? null, 'attendance_reviews', $p->academic_period_id);

            return $review;
        });
    }

    private function evidenceAvailable(?PrivateFile $file): bool
    {
        if (! $file || $file->status !== 'ready' || $file->disk !== 'local' || $file->mime_type !== 'image/jpeg' || ! Storage::disk('local')->exists($file->storage_key)) {
            return false;
        }

        return hash_equals($file->sha256, hash_file('sha256', Storage::disk('local')->path($file->storage_key)));
    }

    private function mutated(AttendanceDay $day, ?User $actor, string $action, ?string $status = null): void
    {
        $from = $day->status;
        $day->status = $status ?? $day->status;
        $day->current_review_id = null;
        $day->revision_number++;
        $day->save();
        $this->event($day, $actor, $from, $action);
        $this->audit->record($actor, 'attendance.'.$action, $day->id, ['fields' => ['status', 'revision_number']], entityType: 'attendance_days', periodId: $day->participation->academic_period_id);
    }

    private function event(AttendanceDay $day, ?User $actor, ?string $from, ?string $reason): void
    {
        AttendanceEvent::create(['attendance_day_id' => $day->id, 'actor_id' => $actor?->id, 'from_status' => $from, 'to_status' => $day->status, 'reason' => $reason, 'occurred_at' => $this->clock()]);
    }

    private function incident(AttendanceDay $day, ?AttendanceInterval $interval, string $kind, string $observation, ?User $actor, ?string $support = null): void
    {
        AttendanceIncident::firstOrCreate(['attendance_day_id' => $day->id, 'attendance_interval_id' => $interval?->id, 'kind' => $kind], ['observation' => $observation, 'recorded_by' => $actor?->id, 'support_reference' => $support]);
    }
}
