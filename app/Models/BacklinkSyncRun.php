<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One backlink sync for a site (§ Links): the index's totals at that moment, how many referring-domain rows
 * were stored, and the error when it failed. Site-scoped.
 *
 * @property string $id
 * @property string $site_id
 * @property string $target
 * @property string $status complete | failed
 * @property int $backlinks
 * @property int $referring_domains
 * @property int $broken_backlinks
 * @property int|null $rank
 * @property int $rows_stored
 * @property int $pages_fetched
 * @property string|null $error
 * @property Carbon $fetched_at
 */
class BacklinkSyncRun extends Model
{
    use BelongsToSite, HasUlids;

    public const COMPLETE = 'complete';

    public const FAILED = 'failed';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'backlinks' => 'integer',
            'referring_domains' => 'integer',
            'broken_backlinks' => 'integer',
            'rank' => 'integer',
            'rows_stored' => 'integer',
            'pages_fetched' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }
}
