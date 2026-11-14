<?php

namespace App\Models;

use App\Models\Concerns\PreservesOperationalHistory;
use App\Models\Concerns\UsesUtcDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupMembership extends Model
{
    use HasFactory, PreservesOperationalHistory, UsesUtcDates;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['valid_from' => 'immutable_datetime', 'valid_until' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function participation(): BelongsTo
    {
        return $this->belongsTo(Participation::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(PlacementGroup::class, 'placement_group_id');
    }
}
