<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Str;

class AuditLogger
{
    // Only metadata known to be safe is allowed, never raw request data or model attributes.
    public function record(?User $actor, string $action, ?int $entityId, array $changes = [], ?string $reason = null, string $entityType = 'users', ?int $periodId = null): void
    {
        $allowed = array_intersect_key($changes, array_flip(['fields', 'role', 'status_before', 'status_after', 'role_changes']));
        if (! request()->attributes->has('audit_request_id')) {
            request()->attributes->set('audit_request_id', (string) Str::uuid());
        }
        AuditEvent::create([
            'actor_id' => $actor?->id, 'academic_period_id' => $periodId,
            'action' => $action, 'entity_type' => $entityType, 'entity_id' => $entityId,
            'request_id' => request()->attributes->get('audit_request_id') ?? (string) Str::uuid(),
            'changes' => $allowed ?: null, 'reason' => $reason, 'occurred_at' => now('UTC'),
        ]);
    }
}
