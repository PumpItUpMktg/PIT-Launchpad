<?php

namespace App\Geo;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IntakeType;
use App\Models\Content;
use App\Models\GeoPrompt;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * The gap → content bridge — the closing half of the GEO growth loop. An absent gap (an active prompt
 * that has been measured but that no AI engine cites the brand for) becomes ONE directed content
 * candidate: a kind=post Content pinned to the gap's service silo, framed by an angle hint that
 * restates the AI-search question we're invisible for. That candidate flows through the normal §6
 * review → publish path (nothing is drafted or published here — generation is never automatic), and the
 * next GEO check re-measures the prompt, so weakness → brief → publish → re-measure is one loop.
 *
 * Bounded (biggest-town first, config `launchpad.geo.bridge.max_gaps`) and idempotent: each bridged
 * candidate carries `external_id = "geo-gap:<geo_prompt_id>"`, so re-running only materializes gaps that
 * have newly gone absent and never double-assigns a prompt already bridged.
 */
class GeoGapBridge
{
    /**
     * Materialize directed candidates for a site's absent GEO gaps.
     *
     * @return array{created: int, reused: int, gaps: int}
     */
    public function bridge(Site $site): array
    {
        $max = max(1, (int) config('launchpad.geo.bridge.max_gaps', 8));

        // Absent gaps carry a service (untagged manual prompts can't be routed to a silo, so they're not
        // bridgeable). Eager-load snapshots for engineSummary() and rank biggest-town first — the same
        // order GeoCoverage surfaces gaps in, so the operator sees the board's top gaps become content.
        $prompts = GeoPrompt::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('active', true)
            ->whereNotNull('service_id')
            ->with(['snapshots', 'coverageArea', 'service'])
            ->get();

        $gaps = $prompts
            ->filter(function (GeoPrompt $p): bool {
                $summary = $p->engineSummary();

                return $summary['measured'] > 0 && $summary['cited'] === 0;
            })
            ->sortBy(fn (GeoPrompt $p): array => [
                $this->tierRank($p->size_tier?->value),
                -$p->engineSummary()['measured'],
            ])
            ->take($max)
            ->values();

        $created = 0;
        $reused = 0;

        foreach ($gaps as $gap) {
            $result = $this->materialize($site, $gap);
            $result === 'created' ? $created++ : $reused++;
        }

        return ['created' => $created, 'reused' => $reused, 'gaps' => $gaps->count()];
    }

    /**
     * One absent gap → a directed candidate. Idempotent on external_id: a candidate already bridged for
     * this prompt (in any status — it may already be drafting or published) is left untouched.
     *
     * @return 'created'|'reused'
     */
    private function materialize(Site $site, GeoPrompt $gap): string
    {
        $externalId = 'geo-gap:'.$gap->id;

        $existing = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('external_id', $externalId)
            ->first();
        if ($existing !== null) {
            return 'reused';
        }

        $siloId = $this->siloFor($site, (string) $gap->service_id);
        $question = trim((string) $gap->prompt);
        $competitors = $this->competitorsFor($gap);

        Content::create([
            'site_id' => $site->id,
            'silo_id' => $siloId,
            'matched_silo_id' => $siloId,
            'kind' => ContentKind::Post,
            'intake_type' => IntakeType::Directed,
            'status' => ContentStatus::Candidate,
            'title' => Str::ucfirst($question),
            'slug' => $this->uniqueSlug((string) $site->id, $question),
            'external_id' => $externalId,
            'draft_lane' => 'geo',
            'angle_hint' => $this->angleHint($question, $competitors),
            'meta' => [
                'geo_gap' => [
                    'geo_prompt_id' => (string) $gap->id,
                    'service_id' => $gap->service_id !== null ? (string) $gap->service_id : null,
                    'service' => $gap->service?->name,
                    'coverage_area_id' => $gap->coverage_area_id !== null ? (string) $gap->coverage_area_id : null,
                    'town' => $gap->coverageArea?->name,
                    'size_tier' => $gap->size_tier?->value,
                    'location_id' => $this->owningLocationId($gap),
                    'intent' => $gap->intent?->value,
                    'intent_label' => $gap->intent?->label(),
                    'competitors' => $competitors,
                    'engines_measured' => $gap->engineSummary()['measured'],
                ],
            ],
            'version' => 1,
        ]);

        return 'created';
    }

    /**
     * The silo a directed candidate for this service pins to — the first silo mapped to the service, or
     * null (uncategorized, re-routable in review) when the service isn't wired into the silo tree yet.
     * Scopes are dropped so the bridge runs the same off a queued job as it does under a request.
     */
    private function siloFor(Site $site, string $serviceId): ?string
    {
        return Silo::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereHas('services', fn ($q) => $q->withoutGlobalScope(SiteScope::class)->whereKey($serviceId))
            ->orderByRaw('case when pillar_content_id is not null then 0 else 1 end')
            ->value('id');
    }

    /**
     * Competitors named in the latest reading per engine — the brands that own the answer today. Kept on
     * the candidate's meta as the differentiation target for the drafter and the operator.
     *
     * @return list<string>
     */
    private function competitorsFor(GeoPrompt $gap): array
    {
        $names = [];
        foreach ($gap->snapshots->sortByDesc('checked_at')->unique('engine') as $snap) {
            foreach ((array) $snap->competitors as $c) {
                $name = trim((string) $c);
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * @param  list<string>  $competitors
     */
    private function angleHint(string $question, array $competitors): string
    {
        $hint = 'AI-search gap: no answer engine cites us for "'.$question.'". Answer this question directly'
            .' and completely — honest, practical guidance grounded in the services provided — so an AI'
            .' assistant can quote us as the source.';

        if ($competitors !== []) {
            $hint .= ' Engines currently cite '.implode(', ', array_slice($competitors, 0, 3)).'; earn the'
                .' citation by being more specific and better substantiated, not by naming them.';
        }

        return $hint;
    }

    /** The brick-and-mortar location that owns this gap's town (first coverage owner), if any. */
    private function owningLocationId(GeoPrompt $gap): ?string
    {
        $ids = data_get($gap->coverageArea, 'source_location_ids');

        return is_array($ids) && isset($ids[0]) ? (string) $ids[0] : null;
    }

    private function tierRank(?string $tier): int
    {
        return match ($tier) {
            'major' => 0, 'large' => 1, 'medium' => 2, 'small' => 3,
            default => 4,
        };
    }

    private function uniqueSlug(string $siteId, string $title): string
    {
        $base = Str::slug($title) ?: 'geo-gap';
        $slug = $base;
        $n = 2;
        while (Content::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }
}
