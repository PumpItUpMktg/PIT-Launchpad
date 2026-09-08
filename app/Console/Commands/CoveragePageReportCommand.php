<?php

namespace App\Console\Commands;

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Locations\TownGeoFallback;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\SiteFinder;
use App\Support\TownName;
use Illuminate\Console\Command;

/**
 * Report how many of a site's served towns ({@see CoverageArea}) have their own published location page —
 * the towns that deep-link on the "Areas we serve" page. A town without a page falls back to the Areas
 * page (by design), so the "missing" list is the backlog of pages still to generate. Read-only.
 *
 * Matching uses the shared {@see TownName} key (strips a trailing ", ST"), the SAME join the areas grid
 * uses, so the report reflects exactly which towns will deep-link.
 */
class CoveragePageReportCommand extends Command
{
    protected $signature = 'launchpad:coverage-page-report {site : Site id, brand name, or domain (partial ok)}
        {--missing : List only the towns still missing a location page}';

    protected $description = 'Report served towns vs the ones with a published location page (+ the missing list).';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $matches = SiteFinder::matches($needle);
        if ($matches->isEmpty()) {
            $this->error("No site matches [{$needle}].");

            return self::FAILURE;
        }
        if ($matches->count() > 1) {
            $this->error("[{$needle}] is ambiguous — it matches {$matches->count()} sites. Re-run with the id.");

            return self::FAILURE;
        }

        /** @var Site $site */
        $site = $matches->first();

        // Every published location page, indexed BOTH ways for the geo-first join: by census geo_id (the
        // anchored ones), and by town-name key carrying whether it is anchored. Coverage-driven resolve
        // (the coverage row carries the geo_id, the page is the anchorable counterpart), so `geo_miss`
        // keeps its one meaning — a same-named page anchored to a DIFFERENT geo than the served town.
        $byGeo = [];
        $byName = [];
        $pageCount = 0;
        foreach (Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNotNull('slug')
            ->get(['title', 'geo_id']) as $page) {
            $key = TownName::key((string) $page->title);
            if ($key === '') {
                continue;
            }
            $pageCount++;
            $geoId = trim((string) $page->geo_id);
            if ($geoId !== '') {
                $byGeo[$geoId] = true;
            }
            $byName[$key] = ['value' => true, 'anchored' => $geoId !== ''];
        }

        // Served towns, deduped by geo_id where present (else name key) — the honest distinct-town identity
        // now that a page carries a geo, so two same-named towns in different counties are no longer merged.
        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('name')
            ->get(['name', 'geo_id'])
            ->map(fn (CoverageArea $a): array => ['name' => trim((string) $a->name), 'geo_id' => trim((string) $a->geo_id)])
            ->filter(fn (array $t): bool => $t['name'] !== '')
            ->unique(fn (array $t): string => $t['geo_id'] !== '' ? 'g:'.$t['geo_id'] : 'n:'.TownName::key($t['name']))
            ->values();

        $geo = new TownGeoFallback('CoveragePageReportCommand', (string) $site->id);
        $missing = $towns
            ->reject(fn (array $t): bool => $geo->resolveByCoverage($t['geo_id'] !== '' ? $t['geo_id'] : null, TownName::key($t['name']), $byGeo, $byName) !== null)
            ->map(fn (array $t): string => $t['name'])
            ->values();
        $geo->report(); // the coverage-driven tripwire's home: one aggregate line + a geo_miss warning
        $withPage = $towns->count() - $missing->count();

        if ($this->option('missing')) {
            $missing->each(fn (string $n) => $this->line($n));

            return self::SUCCESS;
        }

        $this->info($site->brand_name ?: (string) $site->id);
        $this->table(['Metric', 'Count'], [
            ['Served towns', (string) $towns->count()],
            ['Location pages', (string) $pageCount],
            ['Towns with a page', (string) $withPage],
            ['Towns missing a page', (string) $missing->count()],
        ]);

        if ($missing->isNotEmpty()) {
            $this->newLine();
            $this->warn("Towns still missing a location page ({$missing->count()}) — these fall back to the Areas page:");
            $this->line('  '.$missing->implode(', '));
        } else {
            $this->newLine();
            $this->info('Every served town has its own location page.');
        }

        return self::SUCCESS;
    }
}
