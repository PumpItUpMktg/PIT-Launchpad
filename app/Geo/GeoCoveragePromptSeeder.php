<?php

namespace App\Geo;

use App\Enums\GeoPromptKind;
use App\Enums\GeoPromptSource;
use App\Models\CoverageArea;
use App\Models\GeoPrompt;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;

/**
 * Seeds the COVERAGE-CHECK lane — brand-anchored questions ("does {brand} offer {service} in {town}?")
 * per service × published town — to catch when an AI has WRONG or missing facts about a shop's service
 * area. This is an accuracy check, NOT a visibility metric (naming the brand guarantees it's "cited"), so
 * these prompts are `kind=coverage`, excluded from the cited% matrix, and reported separately.
 *
 * Requires a brand name (the question names the business). Bounded (biggest towns first, config
 * `launchpad.geo.seed.max_towns` / `max_prompts`) and idempotent on (service, town).
 */
class GeoCoveragePromptSeeder
{
    /**
     * @param  string|null  $locationId  scope to ONE brick-and-mortar shop's towns (the operator's area
     *                                   selection). Null = all of the tenant's published towns.
     * @return array{created: int, skipped: int, services: int, towns: int}
     */
    public function seed(Site $site, ?string $locationId = null): array
    {
        $brand = trim((string) $site->brand_name);
        if ($brand === '') {
            return ['created' => 0, 'skipped' => 0, 'services' => 0, 'towns' => 0];   // needs a brand to anchor on
        }
        $domain = trim((string) $site->domain_url);

        $maxTowns = max(0, (int) config('launchpad.geo.seed.max_towns', 40));
        $maxPrompts = max(0, (int) config('launchpad.geo.seed.max_prompts', 60));

        $services = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->orderBy('name')->get();

        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('page_selected', true)
            ->orderByRaw("case size_tier when 'major' then 0 when 'large' then 1 when 'medium' then 2 when 'small' then 3 else 4 end")
            ->orderByDesc('population')->orderBy('name')
            ->get();
        if ($locationId !== null) {
            $towns = $towns->filter(fn (CoverageArea $t): bool => in_array($locationId, $t->source_location_ids ?? [], true))->values();
        }
        $towns = $towns->take($maxTowns);

        // Existing (service|town) combos in the coverage lane, for idempotent re-seeding.
        $seen = [];
        foreach (GeoPrompt::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('kind', GeoPromptKind::Coverage->value)->get(['service_id', 'coverage_area_id']) as $existing) {
            $seen[($existing->service_id ?? '').'|'.($existing->coverage_area_id ?? '')] = true;
        }

        $created = 0;
        $skipped = 0;
        foreach ($towns as $town) {
            foreach ($services as $service) {
                if ($created >= $maxPrompts) {
                    break 2;
                }
                $combo = $service->id.'|'.$town->id;
                if (isset($seen[$combo])) {
                    $skipped++;

                    continue;
                }

                GeoPrompt::create([
                    'site_id' => $site->id,
                    'service_id' => $service->id,
                    'coverage_area_id' => $town->id,
                    'size_tier' => $town->size_tier,
                    'kind' => GeoPromptKind::Coverage->value,
                    'source' => GeoPromptSource::Auto->value,
                    'prompt' => $this->question($brand, $domain, (string) $service->name, (string) $town->name, (string) $town->state),
                    'label' => 'Coverage · '.$town->name,
                    'active' => true,
                ]);
                $seen[$combo] = true;
                $created++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'services' => $services->count(), 'towns' => $towns->count()];
    }

    private function question(string $brand, string $domain, string $service, string $town, string $state): string
    {
        $svc = mb_strtolower(trim($service));
        $place = trim($state) !== '' ? "{$town}, {$state}" : $town;
        $who = $domain !== '' ? "{$brand} ({$domain})" : $brand;

        return "Does {$who} provide {$svc} in {$place}? Which towns and areas do they serve?";
    }
}
