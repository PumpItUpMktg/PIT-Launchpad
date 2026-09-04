<?php

namespace App\Models;

use App\Enums\EditReason;
use App\Filament\Resources\ContentEditResource;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One captured operator correction (§7) — original vs edited generated text + a reason tag +
 * coordinates. The quality signal: an edit alone is ambiguous, the reason is what disambiguates.
 * Tenant-scoped ({@see BelongsToSite}): the context-aware SiteScope filters to the locked tenant,
 * with the operator-wide read-across log ({@see ContentEditResource})
 * dropping the scope explicitly to span tenants.
 *
 * @property string $id
 * @property string $site_id
 * @property string $content_id
 * @property string|null $silo_id
 * @property string|null $user_id
 * @property string $field
 * @property EditReason $reason
 * @property string|null $original
 * @property string|null $edited
 */
class ContentEdit extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Content, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason' => EditReason::class,
        ];
    }
}
