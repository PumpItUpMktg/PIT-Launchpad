<?php

namespace App\Models;

use App\Enums\SocialSuggestionStatus;
use App\Enums\SocialTrigger;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A proposed re-share of a post, waiting on the board — or the record of one a rule sent on its own.
 *
 * @property string $id
 * @property string $site_id
 * @property string $content_id
 * @property SocialTrigger $trigger
 * @property SocialSuggestionStatus $status
 * @property string $reason
 * @property array<string, mixed>|null $snapshot
 * @property list<string> $networks
 * @property list<string>|null $location_ids
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 */
class SocialSuggestion extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'trigger' => SocialTrigger::class,
            'status' => SocialSuggestionStatus::class,
            'snapshot' => 'array',
            'networks' => 'array',
            'location_ids' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Content, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    /** @return HasMany<SocialPost, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(SocialPost::class, 'social_suggestion_id');
    }
}
