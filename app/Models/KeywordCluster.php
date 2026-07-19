<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Database\Factories\KeywordClusterFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A demand cluster of corpus terms — the unit the keyword-first generator derives a silo from. Members
 * are {@see KeywordCorpus} rows pointing back via `cluster_id`; the head term is what the silo will be
 * named/hubbed by (what people search, not what the catalog calls the category).
 *
 * @property string $id
 * @property string $site_id
 * @property string|null $label
 * @property string|null $head_term
 * @property string|null $head_canonical
 * @property string|null $intent
 * @property int|null $volume
 * @property int $member_count
 * @property bool $dropped
 * @property string $serp_status
 */
class KeywordCluster extends Model
{
    /** @use HasFactory<KeywordClusterFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return HasMany<KeywordCorpus, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(KeywordCorpus::class, 'cluster_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'volume' => 'integer',
            'member_count' => 'integer',
            'dropped' => 'boolean',
        ];
    }
}
