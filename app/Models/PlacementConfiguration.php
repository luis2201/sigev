<?php

namespace App\Models;

use App\Models\Concerns\PreservesOperationalHistory;
use App\Models\Concerns\UsesUtcDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlacementConfiguration extends Model
{
    use HasFactory, PreservesOperationalHistory, UsesUtcDates;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['practice_starts_on' => 'immutable_date', 'practice_ends_on' => 'immutable_date', 'valid_from' => 'immutable_datetime', 'valid_until' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(HostInstitution::class, 'host_institution_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(InstitutionArea::class, 'institution_area_id');
    }

    public function institutionalTutor(): BelongsTo
    {
        return $this->belongsTo(InstitutionalTutor::class, 'institutional_tutor_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(PlacementGroup::class, 'placement_group_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function intervals(): HasMany
    {
        return $this->hasMany(ScheduleInterval::class, 'placement_configuration_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ParticipationConfiguration::class);
    }
}
