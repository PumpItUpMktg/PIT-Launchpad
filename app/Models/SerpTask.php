<?php

namespace App\Models;

use App\Enums\SerpTaskState;
use Database\Factories\SerpTaskFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A standard-mode DataForSEO task in flight. Tracks the posted task id, the
 * cache key its parsed result will land under, and its state — so a refresh
 * never re-dispatches an already-pending (function × cache_key) task, and the
 * tasks_ready ingest sweep knows what to collect. Failed/expired tasks are
 * retained in the `failed` state (surfaced, never silently dropped).
 *
 * @property string $function
 * @property string|null $task_id
 * @property string $cache_key
 * @property string $query
 * @property int|null $location_code
 * @property string|null $language_code
 * @property string|null $location_coordinate
 * @property SerpTaskState $state
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SerpTask extends Model
{
    /** @use HasFactory<SerpTaskFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => SerpTaskState::class,
            'location_code' => 'integer',
        ];
    }
}
