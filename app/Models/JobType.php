<?php

namespace App\Models;

use App\Enums\JobTypeSource;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\JobTypeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant's pickable job type (§3) — site-scoped vocabulary. `silo`-sourced types are derived from the
 * silo tree (`silo_id` is a SOFT reference, never a DB FK, because silo regeneration is destructive);
 * `native` types are a standalone tenant's own list. This is the vocabulary, NOT the system of record for
 * a job's type — that is snapshotted onto {@see JobTypeAssignment} so a job survives a silo rebuild.
 *
 * @property string $id
 * @property string $site_id
 * @property string $label
 * @property string $slug
 * @property string|null $silo_id soft reference to the originating silo
 * @property JobTypeSource $source
 */
class JobType extends Model
{
    /** @use HasFactory<JobTypeFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => JobTypeSource::class,
        ];
    }

    /** The originating silo — a soft reference (not a DB FK), null for native types or after a silo rebuild.
     *
     * @return BelongsTo<Silo, $this>
     */
    public function silo(): BelongsTo
    {
        return $this->belongsTo(Silo::class);
    }
}
