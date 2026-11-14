<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceIncident extends Model
{
    use HasFactory, UsesUtcDates;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }

    public function interval(): BelongsTo
    {
        return $this->belongsTo(AttendanceInterval::class, 'attendance_interval_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
