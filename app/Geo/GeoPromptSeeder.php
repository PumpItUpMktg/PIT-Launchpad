<?php

namespace App\Geo;

use App\Enums\GeoIntent;
use App\Enums\GeoPromptKind;
use App\Enums\GeoPromptSource;
use App\Enums\SizeTier;
use App\Models\CoverageArea;
use App\Models\GeoPrompt;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Auto-seeds a site's GEO prompt set from the service × TOWN × intent matrix. GEO's geography is the
 * CoverageArea set — the location-linked, size-tiered municipalities the platform actually publishes
 * pages for — so every measured town is one we have a page to win with, and each gap maps to a real page.
 *
 * Bounded + tier-aware: only `page_selected` towns, and the candidate order puts the primary demand
 * question (Hire) across every town — biggest towns first (major → small) — before secondary intents, so
 * a capped run "ensures coverage" (breadth) rather than over-measuring a handful of big towns. Idempotent:
 * an already-present (service, town, intent) combo is skipped, so re-seeding only adds what's new.
 *
 * Prompts stay neutral / demand-shaped (never brand-leading); AI-phrased variety comes from the top-ups.
 */
class GeoPromptSeeder
{
    /**
     * @param  string|null  $locationId  scope to ONE brick-and-mortar shop's towns (via CoverageArea
     *                                   source_location_ids) — the operator's area selection. Null = all
     *                                   of the tenant's published towns.
     * @return array{created: int, skipped: int, services: int, towns: int}
     */
    public function seed(Site $site, ?string $locationId = null): array
    {
        $maxTowns = max(0, (int) config('launchpad.geo.seed.max_towns', 40));
        $maxPrompts = max(0, (int) config('launchpad.geo.seed.max_prompts', 60));
        $brand = trim((string) $site->brand_name);

        $services = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->orderBy('name')->get();

        // Published towns only, biggest first — the cap keeps the highest-value municipalities. When a
        // shop is selected, keep only the towns it serves (so the operator targets one area at a time).
        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('page_selected', true)
            ->orderByRaw($this->tierOrderSql())
            ->orderByDesc('population')
            ->orderBy('name')
            ->get();
        if ($locationId !== null) {
            $towns = $towns->filter(fn (CoverageArea $t): bool => in_array($locationId, $t->source_location_ids ?? [], true))->values();
        }
        $towns = $towns->take($maxTowns);

        // Existing (service|town|intent) combos + prompt texts, for idempotent re-seeding.
        $seen = [];
        $texts = [];
        foreach (GeoPrompt::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get(['service_id', 'coverage_area_id', 'intent', 'prompt']) as $existing) {
            $seen[$this->comboKey($existing->service_id, $existing->coverage_area_id, $existing->intent?->value)] = true;
            $texts[mb_strtolower(trim((string) $existing->prompt))] = true;
        }

        $created = 0;
        $skipped = 0;
        foreach ($this->candidates($services, $towns, $brand) as $c) {
            if ($created >= $maxPrompts) {
                break;
            }

            /** @var Service $service */
            $service = $c['service'];
            /** @var GeoIntent $intent */
            $intent = $c['intent'];
            /** @var CoverageArea|null $town */
            $town = $c['town'];

            $combo = $this->comboKey($service->id, $town?->id, $intent->value);
            $text = $intent->render($service->name, $town?->name, $town?->state, $brand);

            if (isset($seen[$combo]) || isset($texts[mb_strtolower($text)])) {
                $skipped++;

                continue;
            }

            GeoPrompt::create([
                'site_id' => $site->id,
                'service_id' => $service->id,
                'coverage_area_id' => $town?->id,
                'size_tier' => $town?->size_tier,
                'intent' => $intent->value,
                'source' => GeoPromptSource::Auto->value,
                'prompt' => $text,
                'label' => $town !== null ? $service->name.' · '.$town->name : $service->name.' · '.$intent->label(),
                'active' => true,
            ]);
            $seen[$combo] = true;
            $texts[mb_strtolower($text)] = true;
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'services' => $services->count(), 'towns' => $towns->count()];
    }

    /**
     * Re-render the text (and label) of existing AUTO-seeded visibility prompts from the CURRENT town
     * names — the fix after `launchpad:clean-coverage-names`, since a prompt's text is frozen at seed time
     * and cleaning the town name doesn't rewrite it. Only touches source=Auto prompts (never operator-
     * written or AI-phrased assisted variants); optionally scoped to one shop's towns.
     *
     * @return array{updated: int, checked: int}
     */
    public function refresh(Site $site, ?string $locationId = null): array
    {
        $brand = trim((string) $site->brand_name);

        $prompts = GeoPrompt::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', GeoPromptKind::Visibility->value)
            ->where('source', GeoPromptSource::Auto->value)
            ->with(['service', 'coverageArea'])
            ->get();

        if ($locationId !== null) {
            $prompts = $prompts->filter(fn (GeoPrompt $p): bool => $p->coverage_area_id !== null
                && in_array($locationId, (array) data_get($p->coverageArea, 'source_location_ids', []), true))->values();
        }

        $updated = 0;
        foreach ($prompts as $p) {
            if ($p->intent === null || $p->service_id === null) {
                continue;   // can't re-render without a service + intent
            }
            $svcName = (string) data_get($p->service, 'name');
            $town = data_get($p->coverageArea, 'name');
            $state = data_get($p->coverageArea, 'state');
            $townName = is_string($town) ? $town : null;

            $text = $p->intent->render($svcName, $townName, is_string($state) ? $state : null, $brand);
            $label = $townName !== null ? $svcName.' · '.$townName : $svcName.' · '.$p->intent->label();

            if ($text !== $p->prompt || $label !== $p->label) {
                $p->forceFill(['prompt' => $text, 'label' => $label])->save();
                $updated++;
            }
        }

        return ['updated' => $updated, 'checked' => $prompts->count()];
    }

    /**
     * The service × intent × town cells, ordered so the cap keeps the highest-value prompts and ENSURES
     * BREADTH: the primary intent (Hire) is spread across every town — biggest towns first — before any
     * secondary intent, so a capped run covers the whole footprint on the core question rather than going
     * deep on a few towns. Non-geo intents (HowTo/Reviews) are service-level (asked once, no town).
     *
     * @param  Collection<int, Service>  $services
     * @param  Collection<int, CoverageArea>  $towns
     * @return list<array{service: Service, intent: GeoIntent, town: CoverageArea|null}>
     */
    private function candidates($services, $towns, string $brand): array
    {
        $intentRank = [GeoIntent::Hire->value => 0, GeoIntent::Cost->value => 1, GeoIntent::Emergency->value => 2, GeoIntent::Comparison->value => 3, GeoIntent::Reviews->value => 4, GeoIntent::HowTo->value => 5];

        $out = [];
        foreach ($services as $service) {
            foreach (GeoIntent::cases() as $intent) {
                if ($intent->needsBrand() && $brand === '') {
                    continue;
                }
                $targets = $intent->isGeo() ? $towns->all() : [null];
                foreach ($targets as $town) {
                    $out[] = ['service' => $service, 'intent' => $intent, 'town' => $town];
                }
            }
        }

        // Intent first (Hire across all towns before Cost), then town tier (major→small), then service.
        usort($out, function (array $a, array $b) use ($intentRank): int {
            return [$intentRank[$a['intent']->value], $this->tierRank($a['town']), $a['service']->name]
                <=> [$intentRank[$b['intent']->value], $this->tierRank($b['town']), $b['service']->name];
        });

        return $out;
    }

    private function tierRank(?CoverageArea $town): int
    {
        // CoverageArea.size_tier is a raw string column (major|large|medium|small|null).
        return match ($town?->size_tier) {
            SizeTier::Major->value => 0,
            SizeTier::Large->value => 1,
            SizeTier::Medium->value => 2,
            SizeTier::Small->value => 3,
            default => 4,   // ungrouped town, or a service-level (townless) prompt
        };
    }

    /** SQL ordering for the town pull — major first, ungrouped (null tier) last. */
    private function tierOrderSql(): string
    {
        return "case size_tier when 'major' then 0 when 'large' then 1 when 'medium' then 2 when 'small' then 3 else 4 end";
    }

    private function comboKey(?string $serviceId, ?string $townId, ?string $intent): string
    {
        return ($serviceId ?? '').'|'.($townId ?? '').'|'.($intent ?? '');
    }
}
