<?php

namespace App\Locations;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\KeywordSource;
use App\Enums\MarketTier;
use App\Enums\PageType;
use App\Enums\ServiceSiloRole;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;

/**
 * §5 Phase 2 — city-keyword rank tracking for PRIORITY cities. Silos are geo-neutral by hard rule
 * ("geo lives only on location pages"), so the discovery pipeline never creates "{service} {city}"
 * keywords. This does: for each published location page whose Market is priority-tier, it assigns a
 * small, config-driven set of city keywords ("{head} {city}" / "{head} service {city}", head = the
 * page's primary/pillar service — trade-derived, never hardcoded) pinned to that page and that
 * market. They land as `status=scored`, `source=local`, `market_id`=the city, so they flow through
 * the EXISTING tracker (organic rank for the city search + that city's local-pack grid), the
 * "Refresh rankings now" pull, the monthly report position buckets, and the live card — no new
 * plumbing. The page's headline `target_keyword_id` is pointed at "{head} {city}" so its rank shows
 * on the card. Idempotent: re-running updates in place, never duplicates. Bounded by design.
 */
class CityKeywordTracker
{
    /**
     * @return array{cities: int, created: int, keywords: list<string>}
     */
    public function assign(Site $site): array
    {
        $patterns = $this->patterns();

        $priorityMarkets = Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('tier', MarketTier::Priority->value)
            ->pluck('name', 'id'); // [market_id => city name]

        if ($priorityMarkets->isEmpty()) {
            return ['cities' => 0, 'created' => 0, 'keywords' => []];
        }

        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->whereIn('market_id', $priorityMarkets->keys()->all())
            ->get();

        $created = 0;
        $terms = [];
        $cities = [];

        foreach ($pages as $page) {
            $marketId = (string) $page->market_id;
            $city = (string) ($priorityMarkets[$marketId] ?? '');
            $head = $this->headTerm($site, $page);
            if ($city === '' || $head === null) {
                continue;
            }

            $primary = null;
            foreach ($patterns as $i => $pattern) {
                $query = $this->render($pattern, $head, $city);
                if ($query === '') {
                    continue;
                }

                $keyword = Keyword::withoutGlobalScope(SiteScope::class)->updateOrCreate(
                    ['site_id' => $site->id, 'query' => $query],
                    [
                        'source' => KeywordSource::Local->value,
                        'status' => 'scored',
                        'target_content_id' => $page->id,
                        'market_id' => $marketId,
                        // Priority cities are, by definition, priority targets — kept on the fast
                        // tracking cadence and always sampled on the on-demand pull.
                        'priority' => 1,
                    ],
                );

                if ($keyword->wasRecentlyCreated) {
                    $created++;
                }
                $terms[] = $query;
                if ($i === 0) {
                    $primary = $keyword;
                }
            }

            // Point the page's headline keyword at "{head} {city}" so the card shows the city rank.
            if ($primary !== null && $page->target_keyword_id !== $primary->id) {
                $page->forceFill(['target_keyword_id' => $primary->id])->save();
            }
            $cities[$marketId] = true;
        }

        return ['cities' => count($cities), 'created' => $created, 'keywords' => $terms];
    }

    /**
     * The head term for a page's city keywords: its primary service, else the site's first pillar
     * service (trade-derived). Null when the site has no service to anchor on.
     */
    private function headTerm(Site $site, Content $page): ?string
    {
        if ($page->primary_service_id !== null) {
            $service = Service::withoutGlobalScope(SiteScope::class)->find($page->primary_service_id);
            if ($service !== null && trim((string) $service->name) !== '') {
                return trim((string) $service->name);
            }
        }

        $pillar = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('silo_role', ServiceSiloRole::Pillar->value)
            ->orderBy('name')
            ->first();

        $name = $pillar !== null ? trim((string) $pillar->name) : '';

        return $name !== '' ? $name : null;
    }

    private function render(string $pattern, string $head, string $city): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace(['{head}', '{city}'], [$head, $city], $pattern)) ?? '');
    }

    /**
     * @return list<string>
     */
    private function patterns(): array
    {
        $configured = config('launchpad.city_keyword_patterns', ['{head} {city}', '{head} service {city}']);

        return array_values(array_filter(
            is_array($configured) ? $configured : [],
            fn ($p): bool => is_string($p) && str_contains($p, '{head}') && str_contains($p, '{city}'),
        ));
    }
}
