<?php

namespace App\Console\Commands;

use App\Enums\ContentKind;
use App\Enums\PageType;
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

        // Keys of every town that already has a published location page (title "{City}, {ST}").
        $pageKeys = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNotNull('slug')
            ->pluck('title')
            ->map(fn ($t): string => TownName::key((string) $t))
            ->filter(fn (string $k): bool => $k !== '')
            ->unique();

        // Served towns, deduped by their key (the same identity the areas grid links on).
        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('name')
            ->pluck('name')
            ->map(fn ($n): string => trim((string) $n))
            ->filter(fn (string $n): bool => $n !== '')
            ->unique(fn (string $n): string => TownName::key($n))
            ->values();

        $missing = $towns->reject(fn (string $n): bool => $pageKeys->contains(TownName::key($n)))->values();
        $withPage = $towns->count() - $missing->count();

        if ($this->option('missing')) {
            $missing->each(fn (string $n) => $this->line($n));

            return self::SUCCESS;
        }

        $this->info($site->brand_name ?: (string) $site->id);
        $this->table(['Metric', 'Count'], [
            ['Served towns', (string) $towns->count()],
            ['Location pages', (string) $pageKeys->count()],
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
