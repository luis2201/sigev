<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleInterval extends Model
{
    use HasFactory, UsesUtcDates;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['valid_from' => 'immutable_date', 'valid_until' => 'immutable_date', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \LogicException('Los horarios se reemplazan mediante una nueva versión.');
        });
        static::deleting(function (): never {
            throw new \LogicException('No se permite eliminar horarios históricos.');
        });
    }
}
