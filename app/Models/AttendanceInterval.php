<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceInterval extends Model
{
    use HasFactory, UsesUtcDates;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['authorized_start_at' => 'immutable_datetime', 'authorized_end_at' => 'immutable_datetime', 'actual_entry_at' => 'immutable_datetime', 'actual_exit_at' => 'immutable_datetime', 'provisional_closed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }

    public function participation(): BelongsTo
    {
        return $this->belongsTo(Participation::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ParticipationConfiguration::class, 'participation_configuration_id');
    }

    public function configuration(): BelongsTo
    {
        return $this->belongsTo(PlacementConfiguration::class, 'placement_configuration_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ScheduleInterval::class, 'schedule_interval_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'attendance_interval_id');
    }
}
