<?php

namespace App\Models;

use App\Enums\RefreshTrigger;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\RefreshEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A refresh of a published page: refresh count + history. Emitted by §5 (position
 * drop) and §6b/c (merge / news development / manual). §6a only creates the table.
 *
 * @property RefreshTrigger $trigger
 */
class RefreshEvent extends Model
{
    /** @use HasFactory<RefreshEventFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Content, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trigger' => RefreshTrigger::class,
        ];
    }
}
