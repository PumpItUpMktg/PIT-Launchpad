<?php

namespace App\Geo;

use App\Enums\GeoPromptKind;
use App\Models\GeoPrompt;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * The coverage-check read-model — "does the AI know this shop serves these towns?" Reads the brand-anchored
 * `kind=coverage` prompts + their latest reading per engine and returns an accuracy verdict per (service,
 * town): `confirmed` (an engine names the brand and speaks of it OK), `negative` (names it but negatively),
 * `unaware` (measured, but no engine confirms it serves the town → a wrong/missing fact to fix), `unknown`
 * (not measured yet). This is an accuracy view reported APART from the cited% visibility matrix — a wrong
 * verdict is a content/schema/GBP fix, not a blog-post gap. Observed + sampled, never a guarantee.
 */
class GeoCoverageVerification
{
    /**
     * @return array{
     *   rows: list<array{service: ?string, town: ?string, tier: ?string, verdict: string}>,
     *   summary: array{confirmed: int, unaware: int, negative: int, unknown: int},
     *   total: int
     * }
     */
    public function report(Site $site, ?string $locationId = null): array
    {
        $prompts = GeoPrompt::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('active', true)
            ->where('kind', GeoPromptKind::Coverage->value)
            ->with(['snapshots', 'coverageArea', 'service'])
            ->get();

        if ($locationId !== null) {
            $prompts = $prompts->filter(fn (GeoPrompt $p): bool => in_array($locationId, (array) data_get($p->coverageArea, 'source_location_ids', []), true));
        }

        $summary = ['confirmed' => 0, 'unaware' => 0, 'negative' => 0, 'unknown' => 0];
        $rows = [];
        foreach ($prompts as $p) {
            $verdict = $this->verdict($p);
            $summary[$verdict]++;
            $rows[] = [
                'service' => data_get($p->service, 'name'),
                'town' => data_get($p->coverageArea, 'name'),
                'tier' => $p->size_tier?->value,
                'verdict' => $verdict,
            ];
        }

        // Worst first (unaware/negative before confirmed/unknown), then biggest town, then service.
        $verdictRank = ['unaware' => 0, 'negative' => 1, 'unknown' => 2, 'confirmed' => 3];
        $tierRank = ['major' => 0, 'large' => 1, 'medium' => 2, 'small' => 3];
        usort($rows, fn (array $a, array $b): int => [$verdictRank[$a['verdict']], $tierRank[$a['tier']] ?? 4, (string) $a['town'], (string) $a['service']]
            <=> [$verdictRank[$b['verdict']], $tierRank[$b['tier']] ?? 4, (string) $b['town'], (string) $b['service']]);

        return ['rows' => $rows, 'summary' => $summary, 'total' => count($rows)];
    }

    /**
     * The accuracy verdict for one coverage prompt, rolled up across the latest reading per engine.
     */
    private function verdict(GeoPrompt $prompt): string
    {
        $latest = $prompt->snapshots->sortByDesc('checked_at')->unique('engine');
        if ($latest->isEmpty()) {
            return 'unknown';
        }
        // An engine names the brand and isn't negative about it → it knows this shop.
        if ($latest->contains(fn ($s): bool => (bool) $s->cited && $s->sentiment !== 'negative')) {
            return 'confirmed';
        }
        if ($latest->contains(fn ($s): bool => (bool) $s->cited && $s->sentiment === 'negative')) {
            return 'negative';
        }

        return 'unaware';   // measured, but no engine confirmed the brand serves this town
    }
}
