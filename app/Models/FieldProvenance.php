<?php

namespace App\Models;

use App\Enums\ProvenanceState;
use App\Gathering\Provenance;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The provenance sidecar row (gathering relay): (model, field) → seeded|confirmed. A sidecar —
 * deliberately not columns sprinkled across every seedable table. Read/written through
 * {@see Provenance} only. Tenant-scoped ({@see BelongsToSite}): reads resolve a single row by
 * model_id (always the locked tenant's model), so the context-aware SiteScope is a matching no-op.
 *
 * @property string $id
 * @property string $site_id
 * @property string $model_type
 * @property string $model_id
 * @property string $field
 * @property ProvenanceState $state
 */
class FieldProvenance extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => ProvenanceState::class,
        ];
    }
}
