<?php

namespace App\Console\Commands;

use App\Enums\FeedOrigin;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Models\Source;
use Illuminate\Console\Command;

/**
 * Report (read-only): GENERATED-FEED GENERATION — the diagnostics behind the feed-prune relay. Three
 * sections per site, all advisory (changes nothing):
 *
 *  1. CARDINALITY — feeds are minted per keyword × market. This shows the cost (enabled feeds) vs the
 *     coverage (feeds that ever produced an item), and projects the same coverage under a lower-cardinality
 *     grouping. If markets add little UNIQUE production (producing keywords ≈ producing feeds), one feed per
 *     keyword would cover nearly the same ground at 1/(markets-per-keyword) the fetch cost. (A keyword ×
 *     county grouping sits between the two but needs a Market→county mapping the schema does not carry —
 *     Market has only a place geo_id + a state region — so it is noted, not computed.)
 *  2. MALFORMED LABELS — keyword queries with a trailing bare number ("sump pump replacement 2") and market
 *     names that carry digits or repeat their own state ("Halls Cross Roads MD" with region MD) leak into the
 *     Google-News query; flagged with examples so the underlying keyword/market rows can be cleaned.
 *  3. REGENERATION — generated feeds by creation month (the bursts that grew the table) plus the
 *     enabled-vs-disabled split, so it is visible how much is live vs already retired.
 *
 * READ-ONLY, live-only, all tenants (or one via --site).
 */
class ReportFeedGenerationCommand extends Command
{
    protected $signature = 'launchpad:report-feed-generation
        {--site= : Limit to one site id or brand name}
        {--examples=8 : Max malformed examples to list per site}';

    protected $description = 'Report (read-only) generated-feed cardinality, malformed labels, and regeneration bursts.';

    public function handle(): int
    {
        $opt = trim((string) $this->option('site'));
        if ($opt !== '') {
            $site = Site::withoutGlobalScope(VisibleSiteScope::class)->where('id', $opt)->orWhere('brand_name', $opt)->first();
            if ($site === null) {
                $this->error("No site matches [{$opt}].");

                return self::FAILURE;
            }
            $sites = collect([$site]);
        } else {
            $sites = Site::query()->get();
        }

        $examples = max(1, (int) $this->option('examples'));
        $this->info('Read-only · live-only · generated-feed diagnostics (cardinality · malformed labels · regeneration).');

        foreach ($sites as $site) {
            $this->reportSite($site, $examples);
        }

        return self::SUCCESS;
    }

    private function reportSite(Site $site, int $examples): void
    {
        $feeds = Source::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('origin', FeedOrigin::Generated->value)
            ->get(['derived_from', 'enabled', 'last_item_at', 'created_at']);

        if ($feeds->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line("<info>{$site->brand_name}</info> ({$site->id})");

        // ── 1. Cardinality ──────────────────────────────────────────────────────────────────────────
        $enabled = $feeds->where('enabled', true);
        $enabledCount = $enabled->count();
        $producing = $enabled->filter(fn (Source $s): bool => $s->last_item_at !== null);
        $keywordsWithFeeds = $enabled->map(fn (Source $s): ?string => $this->keywordId($s->derived_from))->filter()->unique();
        $producingKeywords = $producing->map(fn (Source $s): ?string => $this->keywordId($s->derived_from))->filter()->unique();

        $markets = Market::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count();
        $routableKeywords = Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereNotNull('silo_id')->count();
        $perKw = $keywordsWithFeeds->count() > 0 ? $enabledCount / $keywordsWithFeeds->count() : 0.0;

        $this->line('  cardinality — '.$routableKeywords.' routable keyword(s) × '.$markets.' market(s)');
        $this->line(sprintf('    kw×market (current): %d enabled feed(s), %d producing (%s), ~%.1f feeds/keyword',
            $enabledCount, $producing->count(), $this->pct($producing->count(), $enabledCount), $perKw));
        $this->line(sprintf('    kw-only (projected): %d feed(s) (one per keyword), ~%d would produce — same coverage at 1/%.1f the cost',
            $keywordsWithFeeds->count(), $producingKeywords->count(), max(1.0, $perKw)));
        $this->line('    kw×county: not computed — Market has no county mapping (place geo_id + state region only); ceiling is the '.$markets.' market(s)');

        // ── 2. Malformed labels ─────────────────────────────────────────────────────────────────────
        $badKeywords = Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->whereNotNull('silo_id')->get(['query'])
            ->filter(fn (Keyword $k): bool => $this->malformedQuery((string) $k->query))
            ->pluck('query')->unique()->values();

        $badMarkets = Market::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get(['name', 'region'])
            ->filter(fn (Market $m): bool => $this->malformedMarket((string) $m->name, is_string($m->region) ? $m->region : ''))
            ->map(fn (Market $m): string => trim($m->name.' '.(is_string($m->region) ? $m->region : '')))
            ->unique()->values();

        $this->line('  malformed — '.$badKeywords->count().' keyword(s) + '.$badMarkets->count().' market(s) with a suspicious label');
        foreach ($badKeywords->take($examples) as $q) {
            $this->line("    · keyword: \"{$q}\"");
        }
        foreach ($badMarkets->take($examples) as $m) {
            $this->line("    · market: \"{$m}\"");
        }

        // ── 3. Regeneration (creation bursts) ───────────────────────────────────────────────────────
        $disabled = $feeds->count() - $enabledCount;
        $byMonth = $feeds->groupBy(fn (Source $s): string => $s->created_at?->format('Y-m') ?? 'unknown')
            ->map->count()->sortKeys();
        $this->line("  regeneration — {$feeds->count()} generated feed(s): {$enabledCount} enabled, {$disabled} disabled (retired). Created by month:");
        foreach ($byMonth as $month => $count) {
            $this->line("    {$month}: {$count}");
        }
    }

    private function keywordId(?string $derivedFrom): ?string
    {
        if (is_string($derivedFrom) && preg_match('/^kw:([^:]+):mkt:/', $derivedFrom, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** A keyword query that ends in a bare number ("sump pump replacement 2") — a data artifact, not a term. */
    private function malformedQuery(string $query): bool
    {
        return preg_match('/\s\d+$/', trim($query)) === 1;
    }

    /** A market whose name carries a digit, or repeats its own state at the end ("Halls Cross Roads MD" / region MD). */
    private function malformedMarket(string $name, string $region): bool
    {
        $name = trim($name);
        if ($name === '' || preg_match('/\d/', $name) === 1) {
            return true;
        }
        $region = trim($region);

        return $region !== '' && preg_match('/\b'.preg_quote($region, '/').'$/i', $name) === 1;
    }

    private function pct(int $n, int $total): string
    {
        return $total > 0 ? number_format($n / $total * 100, 1).'%' : '0%';
    }
}
