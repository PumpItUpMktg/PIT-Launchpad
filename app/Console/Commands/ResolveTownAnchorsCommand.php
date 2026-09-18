<?php

namespace App\Console\Commands;

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Locations\TownPageGeoAnchor;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\SiteFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The last mile of town-page anchoring: the pages {@see TownPageGeoAnchor} refuses to guess at.
 *
 * Those are real ambiguities, not bugs — "Bristol, PA" is a borough AND a township, two municipalities
 * with different GEOIDs, and no name match can say which one a page is about. What CAN say is evidence
 * the database already holds, which this lays out per page:
 *
 *  - is the page LIVE (a wp_post_id), and when was it made;
 *  - does another page already carry each candidate's GEOID — the decisive one, because a candidate that
 *    is taken means this page is the DUPLICATE and wants retiring, not anchoring;
 *  - is the candidate selected for a page at all, and how big is it.
 *
 * Read-only until `--page` and `--geo` name one decision, which it then writes — one page, one GEOID, by
 * a human who looked. Never a bulk guess.
 */
class ResolveTownAnchorsCommand extends Command
{
    protected $signature = 'launchpad:resolve-town-anchors {site : Site id, brand name, or domain (partial ok)}
        {--page= : The town page to anchor (id)}
        {--geo= : The census GEOID to anchor it to}';

    protected $description = 'Lay out the evidence for the town pages the anchor refuses to guess at, and apply one decision.';

    public function handle(TownPageGeoAnchor $anchor): int
    {
        $site = $this->site();
        if ($site === null) {
            return self::FAILURE;
        }

        $pageId = trim((string) $this->option('page'));
        $geoId = trim((string) $this->option('geo'));
        if ($pageId !== '' || $geoId !== '') {
            return $this->apply($site, $pageId, $geoId);
        }

        $plan = $anchor->plan($site);
        $surfaced = [...$plan['ambiguous'], ...$plan['unreachable'], ...$plan['no_coverage']];
        if ($surfaced === []) {
            $this->info('Nothing surfaced — every town page is anchored or anchorable.');

            return self::SUCCESS;
        }

        // Which GEOIDs are already spoken for, and by which page: the difference between "ambiguous" and
        // "duplicate of a page that already won this town".
        $taken = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('page_type', PageType::Location->value)
            ->whereNotNull('geo_id')
            ->get(['id', 'title', 'slug', 'geo_id'])
            ->keyBy(fn (Content $c): string => (string) $c->geo_id);

        $this->info("{$site->brand_name} — ".count($surfaced).' town page(s) the anchor will not guess at');

        foreach ($surfaced as $row) {
            $page = Content::withoutGlobalScope(SiteScope::class)->find($row['page_id']);
            if ($page === null) {
                continue;
            }

            $this->newLine();
            $this->line(sprintf('<options=bold>%s</> (%s)', (string) $page->title, (string) $page->id));
            $this->line(sprintf('  slug %s · %s · created %s',
                (string) $page->slug,
                $page->wp_post_id !== null ? 'LIVE (wp '.$page->wp_post_id.')' : 'not published',
                $page->created_at?->toDateString() ?? '—',
            ));

            $candidates = $row['candidates'] ?? [];
            if ($candidates === []) {
                $this->line('  no candidate coverage area matches this title — check the town name against coverage.');

                continue;
            }

            foreach ($candidates as $candidate) {
                $area = CoverageArea::withoutGlobalScope(SiteScope::class)
                    ->where('site_id', $site->id)->where('geo_id', $candidate['geo_id'])->first();
                $owner = $taken->get((string) $candidate['geo_id']);

                $this->line(sprintf('  · %s [%s] — pop %s%s%s',
                    $candidate['name'],
                    $candidate['geo_id'],
                    number_format((int) ($area->population ?? 0)),
                    $area?->page_selected ? ', selected for a page' : ', NOT selected',
                    $owner !== null
                        ? ' — ALREADY HELD by "'.$owner->title.'" ('.$owner->id.')'
                        : ' — free',
                ));
            }

            $free = array_values(array_filter($candidates, fn (array $c): bool => ! $taken->has((string) $c['geo_id'])));
            if (count($free) === 1) {
                $this->line(sprintf('  → one candidate is free: %s. If this page is that town, anchor it:', $free[0]['name']));
                $this->line(sprintf('    php artisan launchpad:resolve-town-anchors %s --page=%s --geo=%s',
                    $site->id, (string) $page->id, $free[0]['geo_id']));
            } elseif ($free === []) {
                $this->line('  → every candidate is already held by another page: this one is a DUPLICATE, not an anchor problem.');
            }
        }

        return self::SUCCESS;
    }

    /** Write ONE decision: this page is that town. */
    private function apply(Site $site, string $pageId, string $geoId): int
    {
        if ($pageId === '' || $geoId === '') {
            $this->error('Both --page and --geo are needed to write an anchor.');

            return self::FAILURE;
        }

        $page = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->find($pageId);
        if ($page === null) {
            $this->error("No location page [{$pageId}] on this site.");

            return self::FAILURE;
        }

        $area = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('geo_id', $geoId)->first();
        if ($area === null) {
            $this->error("No coverage area with GEOID [{$geoId}] on this site.");

            return self::FAILURE;
        }

        // Two pages claiming one town is the state this whole chain exists to avoid.
        $held = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('geo_id', $geoId)
            ->where('id', '!=', $page->id)->first(['id', 'title']);
        if ($held !== null) {
            $this->error("GEOID [{$geoId}] is already held by \"{$held->title}\" ({$held->id}). Retire one page rather than pointing both at the same town.");

            return self::FAILURE;
        }

        $page->forceFill(['geo_id' => $geoId])->save();
        $this->info("Anchored \"{$page->title}\" to {$area->name} [{$geoId}].");

        return self::SUCCESS;
    }

    private function site(): ?Site
    {
        $needle = (string) $this->argument('site');
        $matches = SiteFinder::matches($needle);
        if ($matches->isEmpty()) {
            $this->error("No site matches [{$needle}]. Available sites:");
            $this->listSites(SiteFinder::all());

            return null;
        }
        if ($matches->count() > 1) {
            $this->error("[{$needle}] is ambiguous — it matches {$matches->count()} sites. Re-run with the id or exact name:");
            $this->listSites($matches);

            return null;
        }

        /** @var Site $site */
        $site = $matches->first();

        return $site;
    }

    /** @param  Collection<int, Site>  $sites */
    private function listSites(Collection $sites): void
    {
        foreach ($sites as $site) {
            $this->line(sprintf('  · %s — %s (%s)', $site->brand_name, $site->domain_url ?? 'no domain', $site->id));
        }
    }
}
