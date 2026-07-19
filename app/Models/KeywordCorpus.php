<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Database\Factories\KeywordCorpusFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant-scoped keyword-corpus row — the raw material the keyword-first generator clusters into
 * structure. Deduped to one row per canonical term per tenant. `disposition` is the operator's keep/
 * dismiss decision and is never wiped by re-accumulation; `cluster_id` is filled by the clustering pass.
 *
 * @property string $id
 * @property string $site_id
 * @property string $term
 * @property string $canonical
 * @property int|null $volume
 * @property int|null $difficulty
 * @property string|null $intent
 * @property string $source
 * @property string|null $seed_term
 * @property string|null $disposition
 * @property string|null $cluster_id
 */
class KeywordCorpus extends Model
{
    /** @use HasFactory<KeywordCorpusFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $table = 'keyword_corpus';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'volume' => 'integer',
            'difficulty' => 'integer',
            'competition' => 'decimal:4',
            'last_refreshed_at' => 'datetime',
        ];
    }
}
