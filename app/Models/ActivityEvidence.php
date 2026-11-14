<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityEvidence extends Model
{
    use HasFactory, UsesUtcDates;

    protected $table = 'activity_evidences';

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(PrivateFile::class, 'private_file_id');
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
