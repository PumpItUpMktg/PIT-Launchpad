<?php

namespace App\Gathering;

use App\Enums\ProvenanceState;
use App\Models\FieldProvenance;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;

/**
 * Field provenance for interview-seeded values (gathering relay) — the single read/write path to
 * the {@see FieldProvenance} sidecar. The contract the whole flow leans on:
 *
 *  - extraction writes values with `seeded` and NEVER overwrites a `confirmed` field
 *    (callers guard with {@see canSeed()});
 *  - an operator save on a review surface flips the touched fields to `confirmed`;
 *  - a manually-entered field has no row at all (null provenance — normal data).
 */
class Provenance
{
    /** Mark a field as interview-seeded. A confirmed field is left untouched. */
    public function seed(Model $model, string $field): void
    {
        $existing = $this->row($model, $field);
        if ($existing !== null && $existing->state === ProvenanceState::Confirmed) {
            return;
        }

        $this->write($model, $field, ProvenanceState::Seeded);
    }

    /** Operator confirmation — the review-surface save path. */
    public function confirm(Model $model, string $field): void
    {
        $this->write($model, $field, ProvenanceState::Confirmed);
    }

    /** Whether extraction may write this field (empty/seeded yes, confirmed no). */
    public function canSeed(Model $model, string $field): bool
    {
        return $this->row($model, $field)?->state !== ProvenanceState::Confirmed;
    }

    public function state(Model $model, string $field): ?ProvenanceState
    {
        return $this->row($model, $field)?->state;
    }

    /**
     * All provenance for one record — the review surfaces render their "from interview" chips
     * from this map.
     *
     * @return array<string, ProvenanceState> field => state
     */
    public function forModel(Model $model): array
    {
        return FieldProvenance::query()
            ->where('model_type', $model->getMorphClass())
            ->where('model_id', (string) $model->getKey())
            ->get()
            ->mapWithKeys(fn (FieldProvenance $p) => [$p->field => $p->state])
            ->all();
    }

    private function row(Model $model, string $field): ?FieldProvenance
    {
        return FieldProvenance::query()
            ->where('model_type', $model->getMorphClass())
            ->where('model_id', (string) $model->getKey())
            ->where('field', $field)
            ->first();
    }

    private function write(Model $model, string $field, ProvenanceState $state): void
    {
        FieldProvenance::query()->updateOrCreate(
            [
                'model_type' => $model->getMorphClass(),
                'model_id' => (string) $model->getKey(),
                'field' => $field,
            ],
            [
                'site_id' => $this->siteIdFor($model),
                'state' => $state,
            ],
        );
    }

    private function siteIdFor(Model $model): string
    {
        return $model instanceof Site ? (string) $model->getKey() : (string) $model->getAttribute('site_id');
    }
}
