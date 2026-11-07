<?php

namespace App\Models;

use App\Enums\SocialNetwork;
use App\Enums\SocialPostStatus;
use App\Enums\SocialTrigger;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One share of one subject (a job or a post) to one channel.
 *
 * @property string $id
 * @property string $site_id
 * @property string $social_channel_id
 * @property string $subject_type job | post
 * @property string $subject_id
 * @property SocialNetwork $network
 * @property SocialTrigger $trigger
 * @property SocialPostStatus $status
 * @property string $copy
 * @property list<string>|null $hashtags
 * @property string|null $image_url
 * @property string|null $link the UTM-tagged link as shared
 * @property string|null $cta
 * @property Carbon|null $scheduled_for
 * @property string|null $provider_post_id
 * @property string|null $permalink
 * @property string|null $error
 * @property array<string, mixed>|null $snapshot
 * @property Carbon|null $sent_at
 * @property Carbon $created_at
 */
class SocialPost extends Model
{
    use BelongsToSite, HasUlids;

    public const SUBJECT_JOB = 'job';

    public const SUBJECT_POST = 'post';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'network' => SocialNetwork::class,
            'trigger' => SocialTrigger::class,
            'status' => SocialPostStatus::class,
            'hashtags' => 'array',
            'snapshot' => 'array',
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SocialChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(SocialChannel::class, 'social_channel_id');
    }
}
