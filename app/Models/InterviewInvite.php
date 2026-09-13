<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Database\Factories\InterviewInviteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A client-facing interview link (relay PR 1). Multi-use — the same link keeps resolving until it expires or
 * is revoked, so a client can leave and come back — and tenant-scoped: the token itself carries the site, so
 * the public route binds the tenant from the row, never from a session. Only the SHA-256 hash of the token is
 * stored. At most one LIVE invite per site: issuing a new one revokes the previous.
 *
 * @property string $id
 * @property string $site_id
 * @property string|null $interview_id
 * @property string $token sha-256 hex
 * @property string|null $recipient_email
 * @property string|null $issued_by
 * @property Carbon $issued_at
 * @property Carbon $expires_at
 * @property Carbon|null $last_opened_at
 * @property int $open_count
 * @property Carbon|null $revoked_at
 */
class InterviewInvite extends Model
{
    /** @use HasFactory<InterviewInviteFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Interview, $this> */
    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    /** Live = not revoked and not past its expiry. Opening it any number of times never spends it. */
    public function isLive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'revoked_at' => 'datetime',
            'open_count' => 'integer',
        ];
    }
}
