<?php

namespace App\Models;

use App\Enums\EditReason;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One captured operator correction (§7) — original vs edited generated text + a reason tag +
 * coordinates. The quality signal: an edit alone is ambiguous, the reason is what disambiguates.
 * Global by design (the read-across view spans tenants); keyed explicitly by site_id.
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
    use HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Content, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
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
