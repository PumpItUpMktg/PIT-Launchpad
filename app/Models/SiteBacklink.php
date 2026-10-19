<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One referring domain's strongest live link to a site, as the backlink index reports it (§ Links).
 * Replaced whole on every sync — never edited by hand. Site-scoped.
 *
 * @property string $id
 * @property string $site_id
 * @property string $domain_from
 * @property string $url_from
 * @property string $url_to
 * @property string $target_path
 * @property int|null $domain_from_rank
 * @property int|null $page_from_rank
 * @property int|null $spam_score
 * @property bool $dofollow
 * @property bool $is_broken
 * @property string|null $anchor
 * @property Carbon|null $first_seen
 * @property Carbon|null $last_seen
 * @property Carbon $synced_at
 */
class SiteBacklink extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'domain_from_rank' => 'integer',
            'page_from_rank' => 'integer',
            'spam_score' => 'integer',
            'dofollow' => 'boolean',
            'is_broken' => 'boolean',
            'first_seen' => 'date',
            'last_seen' => 'date',
            'synced_at' => 'datetime',
        ];
    }
}
