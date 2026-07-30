<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use Database\Factories\GoogleAccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The single shared platform Google grant — the "one email" the operator connects once, that every
 * client adds as a user on their GSC + GA4 property. A §9 PLATFORM secret (one refreshing OAuth
 * token reused across all tenants), deliberately NOT a per-site {@see Connection}: it carries no
 * `site_id`, has no SiteScope, and the §9 launch gate / masker / rotator never touch it. Each Site
 * stores only a non-secret property pointer; the token lives here alone.
 *
 * Singleton — the service reads/writes the one row (newest wins).
 *
 * @property string $id
 * @property string|null $label the connected account email/display (best-effort)
 * @property array<string, mixed>|null $credentials access/refresh tokens + expiry (encrypted at rest)
 * @property list<string>|null $scopes granted OAuth scopes
 * @property string $status {@see ConnectionStatus}
 */
class GoogleAccount extends Model
{
    /** @use HasFactory<GoogleAccountFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** The one shared grant, or null when Google has never been connected. */
    public static function current(): ?self
    {
        return static::query()->latest('updated_at')->first();
    }

    /** True when the grant is revoked or its refresh failed — the operator must reconnect. */
    public function needsReconnect(): bool
    {
        return $this->status === ConnectionStatus::NeedsReconnect->value
            || $this->status === ConnectionStatus::Revoked->value;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'scopes' => 'array',
        ];
    }
}
