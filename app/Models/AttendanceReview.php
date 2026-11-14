<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceReview extends Model
{
    use HasFactory, UsesUtcDates;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['reviewed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }

    public function tutorAssignment(): BelongsTo
    {
        return $this->belongsTo(TutorAssignment::class, 'tutor_assignment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \LogicException('El registro histórico no admite modificaciones.');
        });
        static::deleting(function (): never {
            throw new \LogicException('El registro histórico no admite eliminación.');
        });
    }
}
