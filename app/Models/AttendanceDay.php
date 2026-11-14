<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceDay extends Model
{
    use HasFactory, UsesUtcDates;

    public const LABELS = ['in_progress' => 'En curso', 'pending' => 'Pendiente de revisión', 'observed' => 'Observada / revisión especial', 'approved' => 'Aprobada', 'exceptional' => 'Aprobada excepcionalmente', 'rejected' => 'Rechazada'];

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['local_date' => 'immutable_date', 'closed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    public function participation(): BelongsTo
    {
        return $this->belongsTo(Participation::class);
    }

    public function currentReview(): BelongsTo
    {
        return $this->belongsTo(AttendanceReview::class, 'current_review_id');
    }

    public function intervals(): HasMany
    {
        return $this->hasMany(AttendanceInterval::class, 'attendance_day_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(AttendanceReview::class, 'attendance_day_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(AttendanceIncident::class, 'attendance_day_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class, 'attendance_day_id');
    }
}
