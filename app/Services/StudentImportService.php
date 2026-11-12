<?php

namespace App\Services;

use App\Models\AcademicPeriod;
use App\Models\Career;
use App\Models\Participation;
use App\Models\PrivateFile;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentCareerRecord;
use App\Models\StudentImport;
use App\Models\StudentImportRow;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;
use Throwable;

class StudentImportService
{
    public function __construct(private InstitutionalWorkbook $workbook, private UserManager $users, private AuditLogger $audit) {}

    /** @return array<string,string> */
    public function issues(array $row, AcademicPeriod $period, array $fileErrors = []): array
    {
        $data = $row['data'];
        $errors = $fileErrors;
        $validator = Validator::make($data, [
            'identification_type' => ['required', Rule::in(['cedula', 'pasaporte', 'otro'])],
            'identification_number' => ['required', 'string', 'max:30', 'regex:/^[\pL\pN-]+$/u'],
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:254'],
            'career_code' => ['required', 'string', 'max:40'],
        ]);
        foreach ($validator->errors()->keys() as $column) {
            $errors[$column] ??= $column === 'email' ? 'invalid_email' : 'invalid_value';
        }
        if ($period->status === 'closed') {
            $errors['period'] = 'period_closed';
        }
        $career = Career::where('code', $data['career_code'])->first();
        if (! $career) {
            $errors['career_code'] = 'career_not_found';
        } elseif ($career->status !== 'active') {
            $errors['career_code'] = 'career_inactive';
        }
        $byId = User::where('identification_type', $data['identification_type'])->where('identification_number', $data['identification_number'])->first();
        $byEmail = User::where('email', $data['email'])->first();
        if (($byId && (! $byEmail || $byId->id !== $byEmail->id)) || ($byEmail && ! $byId)) {
            $errors['email'] = 'identity_conflict';
        }
        if ($byId && $byEmail && $byId->id === $byEmail->id && $career) {
            $participation = Participation::whereHas('student', fn ($q) => $q->where('user_id', $byId->id))->where('academic_period_id', $period->id)->first();
            if ($participation && $participation->career_id !== $career->id) {
                $errors['career_code'] = 'career_conflict';
            }
            // This template does not request project changes; preserve any existing project unchanged.
            if ($participation?->project_id && ! DB::table('projects')->where('id', $participation->project_id)->where('academic_period_id', $period->id)->exists()) {
                $errors['project'] = 'project_conflict';
            }
        }

        return $errors;
    }

    /** @return array<int,array> */
    public function inspect(string $path, AcademicPeriod $period): array
    {
        $rows = $this->workbook->read($path);
        $identities = [];
        $emails = [];
        foreach ($rows as $i => $row) {
            $identity = $row['data']['identification_type'].'|'.mb_strtolower($row['data']['identification_number']);
            $identities[$identity][] = $i;
            $emails[$row['data']['email']][] = $i;
        }
        foreach ($rows as $i => &$row) {
            $identity = $row['data']['identification_type'].'|'.mb_strtolower($row['data']['identification_number']);
            if (count($identities[$identity]) > 1) {
                $row['errors']['identification_number'] = 'duplicate_in_file';
            }
            if (count($emails[$row['data']['email']]) > 1) {
                $row['errors']['email'] = 'duplicate_in_file';
            }
            $row['errors'] = $this->issues($row, $period, $row['errors']);
            $row['existing'] = User::where('email', $row['data']['email'])->exists();
        }
        unset($row);

        return $rows;
    }

    public function preview(User $actor, AcademicPeriod $period, UploadedFile $upload): StudentImport
    {
        abort_unless($actor->fresh()->hasRole('admin'), 403);
        if ($period->status === 'closed') {
            throw ValidationException::withMessages(['academic_period_id' => 'El período está cerrado.']);
        }
        $rows = $this->inspect($upload->getRealPath(), $period);
        try {
            $key = $upload->storeAs('student-imports', Str::uuid().'.xlsx', 'local');
        } catch (FilesystemException $e) {
            throw ValidationException::withMessages(['file' => 'No se pudo guardar el archivo en el almacenamiento privado. Solicite a la administración revisar los permisos e intente nuevamente.']);
        }
        if (! $key) {
            throw ValidationException::withMessages(['file' => 'No se pudo almacenar el archivo privado.']);
        }
        try {
            return DB::transaction(function () use ($actor, $period, $upload, $rows, $key) {
                $period = AcademicPeriod::lockForUpdate()->findOrFail($period->id);
                if ($period->status === 'closed') {
                    throw ValidationException::withMessages(['academic_period_id' => 'El período fue cerrado.']);
                }
                $file = PrivateFile::create(['disk' => 'local', 'storage_key' => $key, 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'size_bytes' => $upload->getSize(), 'sha256' => hash_file('sha256', $upload->getRealPath()), 'uploaded_by' => $actor->id, 'status' => 'ready']);
                $batch = StudentImport::create(['academic_period_id' => $period->id, 'source_file_id' => $file->id, 'requested_by' => $actor->id, 'idempotency_key' => (string) Str::uuid(), 'status' => 'pending']);
                foreach ($rows as $row) {
                    StudentImportRow::create(['student_import_id' => $batch->id, 'row_number' => $row['number'], 'row_fingerprint' => hash('sha256', json_encode($row['data'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 'status' => $row['errors'] ? 'error' : 'valid', 'errors' => $row['errors'] ?: null]);
                }
                $this->audit->record($actor, 'import.previewed', $batch->id, entityType: 'student_imports', periodId: $period->id);

                return $batch;
            }, 3);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($key);
            throw $e;
        }
    }

    public function confirm(User $actor, StudentImport $batch): void
    {
        $lock = Cache::lock('student-import:'.$batch->id, 180);
        if (! $lock->get()) {
            return;
        }
        try {
            $this->processChunk($actor, $batch);
        } finally {
            $lock->release();
        }
    }

    private function processChunk(User $actor, StudentImport $batch): void
    {
        abort_unless($actor->fresh()->hasRole('admin'), 403);
        $rows = $this->inspect(Storage::disk('local')->path($batch->file->storage_key), $batch->period);
        if (! hash_equals($batch->file->sha256, hash_file('sha256', Storage::disk('local')->path($batch->file->storage_key)))) {
            throw ValidationException::withMessages(['file' => 'El archivo ya no coincide con la vista previa.']);
        }
        $claimed = DB::transaction(function () use ($batch, $actor) {
            $period = AcademicPeriod::lockForUpdate()->findOrFail($batch->academic_period_id);
            $locked = StudentImport::lockForUpdate()->findOrFail($batch->id);
            if (! in_array($locked->status, ['pending', 'processing'], true)) {
                return false;
            }
            if ($period->status === 'closed') {
                throw ValidationException::withMessages(['file' => 'El período está cerrado; no se ha procesado el lote.']);
            }
            $locked->update(['status' => 'processing', 'started_at' => $locked->started_at ?? now('UTC')]);
            $this->audit->record($actor, 'import.started', $locked->id, entityType: 'student_imports', periodId: $period->id);

            return true;
        }, 3);
        if (! $claimed) {
            return;
        }
        $processed = 0;
        foreach ($rows as $row) {
            $record = StudentImportRow::where('student_import_id', $batch->id)->where('row_number', $row['number'])->firstOrFail();
            if ($record->processed_at !== null) {
                continue;
            }
            if ($processed++ >= 25) {
                break;
            }
            try {
                DB::transaction(function () use ($actor, $batch, $row, $record) {
                    // Match the existing UserManager lock order, then lock period/career/user.
                    Role::orderBy('id')->lockForUpdate()->get();
                    $period = AcademicPeriod::lockForUpdate()->findOrFail($batch->academic_period_id);
                    $admin = User::lockForUpdate()->findOrFail($actor->id);
                    if (! $admin->hasRole('admin') || $admin->must_change_password) {
                        throw ValidationException::withMessages(['account' => 'authorization_changed']);
                    }
                    $career = Career::where('code', $row['data']['career_code'])->lockForUpdate()->first();
                    $errors = $this->issues($row, $period, $row['errors']);
                    if ($errors) {
                        $record->update(['status' => 'error', 'errors' => $errors, 'processed_at' => now('UTC')]);

                        return;
                    }
                    $data = $row['data'];
                    $user = User::where('email', $data['email'])->lockForUpdate()->first();
                    $new = $user === null;
                    if ($new) {
                        $user = $this->users->save($actor, null, $data + ['status' => 'active', 'roles' => ['student']]);
                    } else {
                        $user = $this->users->ensureStudent($actor, $user);
                    }
                    $student = Student::firstOrCreate(['user_id' => $user->id], ['status' => 'active']);
                    $student = Student::lockForUpdate()->findOrFail($student->id);
                    $participation = Participation::where('student_id', $student->id)->where('academic_period_id', $period->id)->first();
                    if (! $participation) {
                        $start = $period->starts_on->format('Y-m-d');
                        $history = StudentCareerRecord::where('student_id', $student->id)->where('career_id', $career->id)->whereDate('valid_from', '<=', $start)->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>', $start))->first();
                        if (! $history) {
                            $later = StudentCareerRecord::where('student_id', $student->id)->where('career_id', $career->id)->whereDate('valid_from', '>', $start)->orderBy('valid_from')->first();
                            $history = StudentCareerRecord::create(['student_id' => $student->id, 'career_id' => $career->id, 'valid_from' => $start, 'valid_until' => $later?->valid_from?->format('Y-m-d'), 'recorded_by' => $actor->id, 'reason' => 'Registro mediante importación académica']);
                        }
                        $participation = Participation::create(['student_id' => $student->id, 'academic_period_id' => $period->id, 'career_id' => $career->id, 'student_career_record_id' => $history->id, 'project_id' => null, 'status' => 'active', 'registered_by' => $actor->id, 'registered_at' => now('UTC')]);
                        $this->audit->record($actor, 'participation.created', $participation->id, entityType: 'participations', periodId: $period->id);
                    }
                    $record->update(['student_id' => $student->id, 'participation_id' => $participation->id, 'status' => $new ? 'created' : 'reused', 'errors' => null, 'processed_at' => now('UTC')]);
                }, 3);
            } catch (Throwable $e) {
                // Never expose SQL bindings, uploaded cell contents or credential hashes.
                $record->update(['status' => 'error', 'errors' => ['row' => 'processing_conflict'], 'processed_at' => now('UTC')]);
            }
        }
        if ($batch->rows()->whereNull('processed_at')->exists()) {
            return;
        }
        DB::transaction(function () use ($batch, $actor) {
            $batch = StudentImport::lockForUpdate()->findOrFail($batch->id);
            $batch->update(['status' => 'completed', 'completed_at' => now('UTC')]);
            $this->audit->record($actor, 'import.completed', $batch->id, entityType: 'student_imports', periodId: $batch->academic_period_id);
        }, 3);
    }

    public function cancel(User $actor, StudentImport $batch): void
    {
        abort_unless($actor->fresh()->hasRole('admin'), 403);
        DB::transaction(function () use ($actor, $batch) {
            $batch = StudentImport::lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'pending') {
                $batch->update(['status' => 'failed', 'completed_at' => now('UTC'), 'error_summary' => 'Vista previa cancelada por administrador.']);
                $this->audit->record($actor, 'import.cancelled', $batch->id, entityType: 'student_imports', periodId: $batch->academic_period_id);
            }
        });
    }
}
