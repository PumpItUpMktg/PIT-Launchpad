<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One competitor intersect for a site (§ Links): who was asked about, who was excluded and why, what came
 * back and what was kept. Site-scoped.
 *
 * @property string $id
 * @property string $site_id
 * @property string $status complete | failed
 * @property list<array{domain: string, appearances: int, source: string}> $targets
 * @property list<array{domain: string, appearances: int, share: float}> $excluded
 * @property int $returned
 * @property int $kept
 * @property int $new
 * @property int $refreshed
 * @property int $pruned
 * @property int $dropped_spam
 * @property int $dropped_rank
 * @property string $scope competitors (the backlink intersect) | opportunities (the local-opportunity search)
 * @property int $queries searches run (opportunities scope)
 * @property string|null $error
 * @property Carbon $ran_at
 */
class LinkProspectRun extends Model
{
    use BelongsToSite, HasUlids;

    public const COMPLETE = 'complete';

    public const FAILED = 'failed';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'targets' => 'array',
            'excluded' => 'array',
            'returned' => 'integer',
            'kept' => 'integer',
            'new' => 'integer',
            'refreshed' => 'integer',
            'pruned' => 'integer',
            'dropped_spam' => 'integer',
            'dropped_rank' => 'integer',
            'queries' => 'integer',
            'ran_at' => 'datetime',
        ];
    }
}
