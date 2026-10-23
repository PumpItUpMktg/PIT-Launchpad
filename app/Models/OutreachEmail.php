<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One outreach email a person approved for a link prospect (§ Links): what was sent, from whom, to whom,
 * and what the provider said. The monthly cap counts rows by `approved_at`, queued and sent alike, so a
 * send still on the worker already holds its place. Site-scoped.
 *
 * @property string $id
 * @property string $site_id
 * @property string $link_prospect_id
 * @property string|null $outreach_kit_id
 * @property string|null $location_id
 * @property string|null $sent_by
 * @property string $kind pitch | follow_up
 * @property string $to_email
 * @property string $from_email
 * @property string|null $from_name
 * @property string $subject
 * @property string $body
 * @property string $status queued | sent | failed
 * @property string|null $provider_message_id
 * @property string|null $error
 * @property Carbon $approved_at
 * @property Carbon|null $sent_at
 */
class OutreachEmail extends Model
{
    use BelongsToSite, HasUlids;

    public const PITCH = 'pitch';

    public const FOLLOW_UP = 'follow_up';

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LinkProspect, $this> */
    public function prospect(): BelongsTo
    {
        return $this->belongsTo(LinkProspect::class, 'link_prospect_id');
    }

    /** Counts against the cap: anything approved that the provider has not refused. */
    public function countsAgainstCap(): bool
    {
        return $this->status !== self::FAILED;
    }
}
