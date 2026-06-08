<?php

namespace App\Models;

use App\Enums\AuditAction;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * An append-only record of a security-relevant action. Updates and deletes are
 * rejected at the model layer so the trail cannot be rewritten; only created_at
 * is tracked (no updated_at). Global by design — the trail spans tenants and
 * operators.
 *
 * @property AuditAction $action
 * @property array<string, mixed>|null $metadata
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory, HasUlids;

    /** Append-only: created_at only, never updated. */
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Audit logs are append-only and cannot be modified.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Audit logs are append-only and cannot be deleted.');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
