<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstitutionArea extends Model
{
    use HasFactory, UsesUtcDates;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(HostInstitution::class, 'host_institution_id');
    }
}
