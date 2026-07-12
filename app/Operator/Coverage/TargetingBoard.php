<?php

namespace App\Operator\Coverage;

use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * The Targeting cards read model (menu-reorg relay): one card per silo carrying its keyword
 * targets, replacing the two flat tables (Targets & gaps + Silos) as the primary surface —
 * those stay routable as drill-downs. Per silo: §4's viability state, the covered/gap split,
 * and the top targets in queue order (operator priority first, then §5 opportunity). Keywords
 * no silo claims surface in an "unassigned" band so nothing hides.
 */
class TargetingBoard
{
    /** Targets shown on a card before the "+N more" drill-down link takes over. */
    public const CARD_LIMIT = 8;

    public function __construct(private readonly SiloManager $silos) {}

    /**
     * @return array{silos: list<array<string, mixed>>, unassigned: list<array<string, mixed>>, unassigned_total: int, threshold: int}
     */
    public function for(Site $site): array
    {
        $keywords = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderByDesc('priority')
            ->orderByDesc('opportunity_score')
            ->get()
            ->groupBy(fn (Keyword $k) => (string) ($k->silo_id ?? ''));

        $cards = [];
        $silos = Silo::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('name')
            ->get();

        foreach ($silos as $silo) {
            /** @var Collection<int, Keyword> $targets */
            $targets = $keywords->get((string) $silo->id, collect());

            $cards[] = [
                'id' => (string) $silo->id,
                'name' => (string) $silo->name,
                'type' => $silo->type->value ?? null,
                'viable' => $this->silos->isViable($silo),
                'warning' => $this->silos->viabilityWarning($silo),
                'total' => $targets->count(),
                'covered' => $targets->whereNotNull('target_content_id')->count(),
                'gaps' => $targets->whereNull('target_content_id')->count(),
                'keywords' => $targets->take(self::CARD_LIMIT)->map(fn (Keyword $k) => $this->row($k))->values()->all(),
            ];
        }

        $unassigned = $keywords->get('', collect());

        return [
            'silos' => $cards,
            'unassigned' => $unassigned->take(self::CARD_LIMIT)->map(fn (Keyword $k) => $this->row($k))->values()->all(),
            'unassigned_total' => $unassigned->count(),
            'threshold' => $this->silos->threshold(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Keyword $keyword): array
    {
        return [
            'id' => (string) $keyword->id,
            'query' => (string) $keyword->query,
            'volume' => $keyword->volume,
            'opportunity' => $keyword->opportunity_score !== null ? round((float) $keyword->opportunity_score, 2) : null,
            'priority' => (int) $keyword->priority,
            'covered' => $keyword->target_content_id !== null,
            'intent' => $keyword->intent,
        ];
    }
}
