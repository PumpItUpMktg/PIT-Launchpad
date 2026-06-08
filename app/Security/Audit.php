<?php

namespace App\Security;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * The thin recorder for the append-only audit trail. Call sites pass the action,
 * the target model, and the acting user — never a secret value. Metadata is for
 * non-sensitive context (which connection, which provider), so the trail can be
 * surfaced to clients without leaking anything.
 */
class Audit
{
    /**
     * @param  array<string, mixed>  $metadata  non-sensitive context only — never a credential value
     */
    public function log(
        AuditAction $action,
        ?Model $target = null,
        ?string $actorId = null,
        array $metadata = [],
    ): AuditLog {
        return AuditLog::create([
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => $target?->getMorphClass(),
            'target_id' => $target?->getKey(),
            'metadata' => $metadata,
        ]);
    }
}
