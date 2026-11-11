<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use UsesUtcDates;

    public const LABELS = ['admin' => 'Administrador', 'coordinator' => 'Coordinador general', 'tutor' => 'Docente tutor', 'student' => 'Estudiante'];

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['code', 'name'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['assigned_by', 'assigned_at', 'revoked_at', 'reason'])->wherePivotNull('revoked_at');
    }
}
