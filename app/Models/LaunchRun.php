<?php

namespace App\Models;

use App\Enums\LaunchRunStatus;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One orchestrated full-site push to WordPress — the operator's go-live audit:
 * what pushed, what was skipped (and why), what failed (and why), with the WP ids
 * the plugin returned. Headline tallies are columns; the per-item detail is in
 * `items`.
 *
 * @property LaunchRunStatus $status
 * @property int $pushed
 * @property int $skipped
 * @property int $failed
 * @property array<int, array<string, mixed>>|null $items
 * @property string|null $actor_id
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
class LaunchRun extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Append one item outcome and bump the matching headline tally. `state` is
     * pushed | skipped | failed; `kind` is silo | content | redirects.
     */
    public function recordItem(string $kind, string $id, string $label, string $state, string $message = '', ?int $wpId = null): void
    {
        $items = $this->items ?? [];
        $items[] = array_filter([
            'kind' => $kind,
            'id' => $id,
            'label' => $label,
            'state' => $state,
            'message' => $message,
            'wp_id' => $wpId,
        ], fn ($value) => $value !== null && $value !== '');
        $this->items = $items;

        match ($state) {
            'skipped' => $this->skipped++,
            'failed' => $this->failed++,
            default => $this->pushed++,
        };
    }

    public function summary(): string
    {
        return "{$this->pushed} pushed · {$this->skipped} skipped · {$this->failed} failed";
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => LaunchRunStatus::class,
            'items' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
