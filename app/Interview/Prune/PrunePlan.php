<?php

namespace App\Interview\Prune;

use App\Enums\SpokeTag;

/**
 * The prune plan: the candidate rows (volume-weighted, tagged) plus the completeness
 * state. The hard gate — a blueprint is confirmable only when every non-fringe spoke
 * has a decision — is computed here. Fringe rows are the Routing-layer handoff and are
 * excluded from the gate.
 */
final class PrunePlan
{
    /**
     * @param  list<PruneRow>  $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly bool $confirmed = false,
    ) {}

    /**
     * @return list<PruneRow>
     */
    public function decidable(): array
    {
        return array_values(array_filter($this->rows, fn (PruneRow $r) => ! $r->isFringe()));
    }

    /**
     * @return list<PruneRow>
     */
    public function pending(): array
    {
        return array_values(array_filter($this->decidable(), fn (PruneRow $r) => ! $r->isDecided()));
    }

    /**
     * @return list<PruneRow>
     */
    public function fringe(): array
    {
        return array_values(array_filter($this->rows, fn (PruneRow $r) => $r->isFringe()));
    }

    public function isComplete(): bool
    {
        return $this->pending() === [];
    }

    /**
     * Non-fringe rows grouped by silo, volume-sorted within each silo (pillar first,
     * then highest upside first) — the asymmetry-of-effort view the prune surface reads:
     * decision energy goes to the high-volume lean-ins.
     *
     * @return array<string, list<PruneRow>>
     */
    public function bySilo(): array
    {
        $grouped = [];
        foreach ($this->decidable() as $row) {
            $grouped[$row->silo][] = $row;
        }

        foreach ($grouped as &$rows) {
            usort($rows, function (PruneRow $a, PruneRow $b) {
                return [$b->isPillar, $b->volume ?? -1, $a->name] <=> [$a->isPillar, $a->volume ?? -1, $b->name];
            });
        }

        return $grouped;
    }

    /**
     * Per-silo summary: stated core vs lean-ins (adjacent/connecting) and their combined
     * upside, plus the decided/pending split.
     *
     * @return array<string, array{total: int, core: int, lean_ins: int, lean_in_volume: int, pending: int}>
     */
    public function siloSummaries(): array
    {
        $summaries = [];
        foreach ($this->bySilo() as $silo => $rows) {
            $leanIns = array_filter($rows, fn (PruneRow $r) => in_array($r->tag, [SpokeTag::Adjacent, SpokeTag::Connecting], true));
            $summaries[$silo] = [
                'total' => count($rows),
                'core' => count(array_filter($rows, fn (PruneRow $r) => $r->tag === SpokeTag::Core)),
                'lean_ins' => count($leanIns),
                'lean_in_volume' => array_sum(array_map(fn (PruneRow $r) => $r->volume ?? 0, $leanIns)),
                'pending' => count(array_filter($rows, fn (PruneRow $r) => ! $r->isDecided())),
            ];
        }

        return $summaries;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'confirmed' => $this->confirmed,
            'complete' => $this->isComplete(),
            'pending' => count($this->pending()),
            'decidable' => count($this->decidable()),
            'fringe' => count($this->fringe()),
            'rows' => array_map(fn (PruneRow $r) => $r->toArray(), $this->rows),
        ];
    }
}
