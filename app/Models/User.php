<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;
    use UsesUtcDates;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['name', 'identification_type', 'identification_number', 'email'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'must_change_password' => 'boolean', 'password_changed_at' => 'datetime', 'last_login_at' => 'datetime'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot(['assigned_by', 'assigned_at', 'revoked_at', 'reason'])->wherePivotNull('revoked_at');
    }

    public function hasRole(string $code): bool
    {
        return $this->status === 'active' && $this->roles->contains('code', $code);
    }

    public function roleLabel(): string
    {
        return $this->roles->map(fn ($role) => Role::LABELS[$role->code] ?? $role->code)->join(', ') ?: 'Sin rol asignado';
    }

    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }
}
