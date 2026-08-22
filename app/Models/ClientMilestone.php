<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A derived, client-visible narrative beat (§ Client Dashboard v1) — first_page_indexed, blog_post_10, etc.
 * Always derived from the metric spine + page index states, never hand-entered. Keyed on (site_id, key)
 * so re-derivation is idempotent.
 *
 * @property string $site_id
 * @property string $key
 * @property Carbon $occurred_on
 * @property array<string, mixed>|null $payload
 * @property bool $is_client_visible
 */
class ClientMilestone extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'payload' => 'array',
            'is_client_visible' => 'boolean',
        ];
    }
}
