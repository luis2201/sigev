<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HostInstitution extends Model
{
    use HasFactory, UsesUtcDates;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    public function areas(): HasMany
    {
        return $this->hasMany(InstitutionArea::class, 'host_institution_id');
    }

    public function institutionalTutors(): HasMany
    {
        return $this->hasMany(InstitutionalTutor::class, 'host_institution_id');
    }

    public function configurations(): HasMany
    {
        return $this->hasMany(PlacementConfiguration::class, 'host_institution_id');
    }
}
