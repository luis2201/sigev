<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Activity extends Model
{
    use HasFactory, UsesUtcDates;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function interval(): BelongsTo
    {
        return $this->belongsTo(AttendanceInterval::class, 'attendance_interval_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function evidence(): HasOne
    {
        return $this->hasOne(ActivityEvidence::class, 'activity_id');
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
