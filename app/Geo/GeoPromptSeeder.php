<?php

namespace App\Geo;

use App\Enums\GeoIntent;
use App\Enums\GeoPromptSource;
use App\Models\GeoPrompt;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Auto-seeds a site's GEO prompt set from the service × market × intent matrix, tagging each prompt with
 * its dimensions so the coverage matrix and gap → content bridge can read them. Bounded (the §5 way):
 * PRIORITY markets first, capped market fan-out, capped total prompts — because prompts multiply fast and
 * every one is a metered engine call. Idempotent: an already-present (service, market, intent) combo is
 * skipped, so re-seeding only adds what's new (e.g. after a new service or market lands).
 *
 * Prompts are deterministic templates (neutral, demand-shaped) — cheap, re-runnable, and not brand-leading;
 * AI-phrased variety comes with the assisted weakness top-ups (a later phase).
 */
class GeoPromptSeeder
{
    /**
     * @return array{created: int, skipped: int, services: int, markets: int}
     */
    public function seed(Site $site): array
    {
        $maxMarkets = max(0, (int) config('launchpad.geo.seed.max_markets', 5));
        $maxPrompts = max(0, (int) config('launchpad.geo.seed.max_prompts', 60));
        $brand = trim((string) $site->brand_name);

        $services = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->orderBy('name')->get();

        // Priority-tier markets first, then coverage; capped.
        $markets = Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderByRaw("case when tier = 'priority' then 0 else 1 end")
            ->orderBy('name')
            ->limit($maxMarkets)
            ->get();

        // Existing (service|market|intent) combos + prompt texts, for idempotent re-seeding.
        $seen = [];
        $texts = [];
        foreach (GeoPrompt::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get(['service_id', 'market_id', 'intent', 'prompt']) as $existing) {
            $seen[$this->comboKey($existing->service_id, $existing->market_id, $existing->intent?->value)] = true;
            $texts[mb_strtolower(trim((string) $existing->prompt))] = true;
        }

        $candidates = $this->candidates($services, $markets, $brand);

        $created = 0;
        $skipped = 0;
        foreach ($candidates as $c) {
            if ($created >= $maxPrompts) {
                break;
            }

            /** @var Service $service */
            $service = $c['service'];
            /** @var GeoIntent $intent */
            $intent = $c['intent'];
            /** @var Market|null $market */
            $market = $c['market'];

            $combo = $this->comboKey($service->id, $market?->id, $intent->value);
            $text = $intent->render($service->name, $market?->name, $market?->region, $brand);

            if (isset($seen[$combo]) || isset($texts[mb_strtolower($text)])) {
                $skipped++;

                continue;
            }

            GeoPrompt::create([
                'site_id' => $site->id,
                'service_id' => $service->id,
                'market_id' => $market?->id,
                'intent' => $intent->value,
                'source' => GeoPromptSource::Auto->value,
                'prompt' => $text,
                'label' => $service->name.' · '.$intent->label(),
                'active' => true,
            ]);
            $seen[$combo] = true;
            $texts[mb_strtolower($text)] = true;
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'services' => $services->count(), 'markets' => $markets->count()];
    }

    /**
     * The service × intent × market cells, ordered so the cap keeps the highest-value prompts: highest-value
     * intents (hire/cost) first, priority markets before coverage, brand/service-level (non-geo) intents
     * always kept.
     *
     * @param  Collection<int, Service>  $services
     * @param  Collection<int, Market>  $markets
     * @return list<array{service: Service, intent: GeoIntent, market: Market|null}>
     */
    private function candidates($services, $markets, string $brand): array
    {
        $intentRank = [GeoIntent::Hire->value => 0, GeoIntent::Cost->value => 1, GeoIntent::Emergency->value => 2, GeoIntent::Comparison->value => 3, GeoIntent::Reviews->value => 4, GeoIntent::HowTo->value => 5];

        $out = [];
        foreach ($services as $service) {
            foreach (GeoIntent::cases() as $intent) {
                if ($intent->needsBrand() && $brand === '') {
                    continue;
                }
                $targets = $intent->isGeo() ? $markets->all() : [null];
                foreach ($targets as $market) {
                    $out[] = ['service' => $service, 'intent' => $intent, 'market' => $market];
                }
            }
        }

        usort($out, function (array $a, array $b) use ($intentRank): int {
            $aTier = $a['market'] === null ? 0 : ($a['market']->tier->value === 'priority' ? 0 : 1);
            $bTier = $b['market'] === null ? 0 : ($b['market']->tier->value === 'priority' ? 0 : 1);

            return [$intentRank[$a['intent']->value], $aTier, $a['service']->name]
                <=> [$intentRank[$b['intent']->value], $bTier, $b['service']->name];
        });

        return $out;
    }

    private function comboKey(?string $serviceId, ?string $marketId, ?string $intent): string
    {
        return ($serviceId ?? '').'|'.($marketId ?? '').'|'.($intent ?? '');
    }
}
