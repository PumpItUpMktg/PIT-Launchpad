<?php

namespace App\Models;

use App\Enums\LinkProspectKind;
use App\Enums\LinkProspectStatus;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A domain that links to the site's local competitors but not to the site (§ Links). Found by the competitor
 * intersect, worked by hand: `status` and `notes` are the operator's and survive every re-run; the index
 * fields are refreshed each time the intersect returns the domain.
 *
 * @property string|null $url the page the opportunity lives on (opportunity prospects)
 * @property string|null $query
 * @property string|null $location_id
 * @property list<string>|null $offices
 * @property int $locality 0–3
 * @property string|null $contact_email
 * @property string $id
 * @property string $site_id
 * @property string $domain
 * @property int|null $rank
 * @property int|null $spam_score
 * @property list<string> $competitors
 * @property int $competitor_count
 * @property LinkProspectKind $kind
 * @property LinkProspectStatus $status
 * @property string|null $notes
 * @property Carbon|null $first_seen
 * @property Carbon $found_at
 */
class LinkProspect extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'offices' => 'array',
            'locality' => 'integer',
            'rank' => 'integer',
            'spam_score' => 'integer',
            'competitors' => 'array',
            'competitor_count' => 'integer',
            'kind' => LinkProspectKind::class,
            'status' => LinkProspectStatus::class,
            'first_seen' => 'date',
            'found_at' => 'datetime',
        ];
    }
}
