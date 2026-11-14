<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstitutionalTutor extends Model
{
    use HasFactory, UsesUtcDates;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function institution(): BelongsTo
    {
        return $this->belongsTo(HostInstitution::class, 'host_institution_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(InstitutionArea::class, 'institution_area_id');
    }
}
