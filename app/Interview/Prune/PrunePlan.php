<?php

namespace App\Interview\Prune;

use App\Enums\KeywordIntent;
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
        public readonly int $ownPageBar = 0,
    ) {}

    /**
     * Pre-decided defaults the owner reviews instead of deciding from scratch (§2):
     *  - pillar → always a page ('hub'), no per-spoke decision;
     *  - core ≥ bar → its own page; core < bar → fold into the pillar (still a section — floor);
     *  - supporting (adjacent ∪ connecting), transactional/commercial → fold into the most-related
     *    core page (opt-in to promote);
     *  - supporting, INFORMATIONAL → the silo's blog target queue (an article target, not a page
     *    section — the longtail routing rule). Operator can override either direction per row.
     * `fold_into` is the target spoke id (null = the silo pillar).
     *
     * @return array<string, array{bucket: string, disposition: string, fold_into: string|null}>
     */
    public function defaults(): array
    {
        $out = [];
        foreach ($this->bySilo() as $rows) {
            $pillar = collect($rows)->firstWhere('isPillar', true);
            $cores = array_values(array_filter($rows, fn (PruneRow $r) => $r->tag === SpokeTag::Core && ! $r->isPillar));
            usort($cores, fn (PruneRow $a, PruneRow $b) => ($b->volume ?? -1) <=> ($a->volume ?? -1));
            $relatedCore = $cores[0] ?? $pillar; // most-related core for supporting folds (else pillar)

            foreach ($rows as $row) {
                if ($row->isPillar) {
                    $out[$row->id] = ['bucket' => 'pillar', 'disposition' => 'hub', 'fold_into' => null];

                    continue;
                }
                if ($row->tag === SpokeTag::Core) {
                    $page = ($row->volume ?? 0) >= $this->ownPageBar;
                    $out[$row->id] = ['bucket' => 'core', 'disposition' => $page ? 'page' : 'fold', 'fold_into' => $page ? null : $pillar?->id];

                    continue;
                }
                if ($row->intent === KeywordIntent::Informational) {
                    $out[$row->id] = ['bucket' => 'supporting', 'disposition' => 'blog_target', 'fold_into' => null];

                    continue;
                }
                $out[$row->id] = ['bucket' => 'supporting', 'disposition' => 'fold', 'fold_into' => $relatedCore?->id];
            }
        }

        return $out;
    }

    /**
     * Silos the engine flags as dead (advisory — operator confirms the fold): no non-pillar
     * core clears the own-page bar AND the silo's total spoke volume is below the bar. A
     * thin-but-real silo (a high-volume supporting spoke) is NOT flagged; pillar volume is
     * never the gate (pillars are structurally low-volume hubs).
     *
     * @return list<string>
     */
    public function deadSilos(): array
    {
        $dead = [];
        foreach ($this->bySilo() as $silo => $rows) {
            $coreClears = collect($rows)->contains(fn (PruneRow $r) => $r->tag === SpokeTag::Core && ! $r->isPillar && ($r->volume ?? 0) >= $this->ownPageBar);
            $sumVolume = (int) collect($rows)->sum(fn (PruneRow $r) => $r->volume ?? 0);
            if (! $coreClears && $sumVolume < $this->ownPageBar) {
                $dead[] = $silo;
            }
        }

        return $dead;
    }

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
