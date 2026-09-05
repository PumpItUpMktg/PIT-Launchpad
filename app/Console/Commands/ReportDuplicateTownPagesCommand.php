<?php

namespace App\Console\Commands;

use App\Build\DuplicateTownSweeper;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Support\PublicUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Report (Indexing/location integrity): DUPLICATE LIVE town pages — the same town published twice on one
 * site. It exists because {@see DuplicateTownSweeper} (and the explicit
 * `launchpad:dedupe-town-pages`) deliberately leave these in place: the sweeper never auto-deletes a
 * PUBLISHED page, and it groups by `(parent_location_id, townKey)` — so a MIS-PARENTED pair (two pages
 * for the same town under different parents) is never even compared. This report groups by
 * `(site_id, townKey)` IGNORING the parent, exactly to surface the pairs the sweeper's key splits.
 *
 * Not every same-name pair is a duplicate — several market names are also town names (Hoboken,
 * Hackensack, Montclair, Doylestown, Reading, New Brunswick, Buckingham). So each same-name group is
 * CLASSIFIED, and only one class is a duplicate to resolve:
 *
 *   - market-landing vs town  — one page is the MARKET's own hub landing (page_type=Location WITH a
 *                               `location_id`), the other a town page under a different market. Two
 *                               legitimately distinct pages sharing a name — NOT a duplicate.
 *   - same-market duplicate   — two+ TOWN pages (no `location_id`) under the SAME `parent_location_id`,
 *                               both published. THE duplicate to resolve.
 *   - cross-market same-name  — two+ town pages under DIFFERENT parents (the Middletown/Montgomery
 *                               shape). May be correct (two real towns) or a mis-assignment — review,
 *                               never auto-remove.
 *
 * Each page prints its URL (so /hoboken-nj vs /hoboken-nj/hoboken-nj settles it at a glance), slug,
 * parent-location label, and index state. READ-ONLY, live-only (status=published, not soft-deleted),
 * all tenants — there is no --execute: removing a live page is a deliberate take-down (Operate →
 * Locations) then `launchpad:dedupe-town-pages`, never an unattended sweep.
 */
class ReportDuplicateTownPagesCommand extends Command
{
    protected $signature = 'launchpad:report-duplicate-town-pages {--site= : Limit to one site id or brand name}';

    protected $description = 'Report (read-only) duplicate LIVE town pages (same site + town), classified so only true duplicates are flagged.';

    /** @var array<string, string> location id → hub-landing label, cached */
    private array $labels = [];

    /** @var array<string, ?string> site id → domain_url, cached */
    private array $domains = [];

    public function handle(): int
    {
        $siteId = $this->resolveSiteId();
        if ($siteId === false) {
            return self::FAILURE;
        }

        // LIVE town/hub pages: page_type=Location, published, not soft-deleted (the default scope excludes
        // deleted). Both hubs (location_id set) and town pages (location_id null) — a market landing and a
        // same-named town must land in the same group so the classifier can tell them apart.
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->get(['id', 'site_id', 'title', 'slug', 'page_type', 'location_id', 'parent_location_id']);

        $groups = $pages
            ->groupBy(fn (Content $c): string => $c->site_id.'|'.$this->townKey((string) $c->title))
            ->filter(fn (Collection $g): bool => $g->count() > 1);

        $this->info('Read-only · live-only (status=published, not soft-deleted) · all tenants'.($siteId !== null ? ' (scoped to one site)' : '').'.');
        $this->newLine();

        if ($groups->isEmpty()) {
            $this->info('No same-town, same-site groups with more than one live page.');

            return self::SUCCESS;
        }

        $trueDupGroups = 0;
        $trueDupExtraPages = 0;
        $crossMarketGroups = 0;
        $landingVsTownGroups = 0;

        foreach ($groups as $group) {
            $townPages = $group->filter(fn (Content $c): bool => $c->location_id === null);
            $landings = $group->filter(fn (Content $c): bool => $c->location_id !== null);

            // Same-market true duplicates: >1 town page under the SAME parent.
            $byParent = $townPages->groupBy(fn (Content $c): string => (string) ($c->parent_location_id ?? '∅'));
            $sameMarketDupes = $byParent->filter(fn (Collection $g): bool => $g->count() > 1);

            $isTrueDup = $sameMarketDupes->isNotEmpty();
            $isCrossMarket = ! $isTrueDup && $townPages->pluck('parent_location_id')->unique()->count() > 1;
            $isLandingVsTown = ! $isTrueDup && ! $isCrossMarket && $landings->isNotEmpty() && $townPages->isNotEmpty();

            $verdict = match (true) {
                $isTrueDup => '❌ SAME-MARKET DUPLICATE — resolve',
                $isCrossMarket => '⚠ cross-market same-name — review (may be two real towns, or a mis-assignment)',
                $isLandingVsTown => '✓ market landing + town — distinct by design',
                default => 'ℹ same-name group — review',
            };

            if ($isTrueDup) {
                $trueDupGroups++;
                // Extra pages = every same-market town page beyond one canonical per parent.
                foreach ($sameMarketDupes as $g) {
                    $trueDupExtraPages += $g->count() - 1;
                }
            } elseif ($isCrossMarket) {
                $crossMarketGroups++;
            } elseif ($isLandingVsTown) {
                $landingVsTownGroups++;
            }

            $town = trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', (string) $group->first()->title));
            $this->line("<comment>{$town}</comment> ({$group->count()} live pages) — {$verdict}");

            foreach ($group as $page) {
                $type = $page->location_id !== null ? 'market-landing' : 'town-page';
                $parentLabel = $page->location_id !== null
                    ? $this->locationLabel($page->location_id).' (its own market)'
                    : ($page->parent_location_id !== null ? $this->locationLabel($page->parent_location_id) : '— (no parent)');

                $this->line(sprintf(
                    '    [%-14s] %s · parent: %s · index: %s · slug: %s',
                    $type,
                    $this->publicUrl($page),
                    $parentLabel,
                    $this->indexState($page),
                    (string) $page->slug,
                ));
            }
            $this->newLine();
        }

        $this->line("<info>Summary</info> — {$trueDupGroups} same-market duplicate town(s) to resolve "
            ."({$trueDupExtraPages} extra live page(s)); {$crossMarketGroups} cross-market same-name to review; "
            ."{$landingVsTownGroups} landing+town (working as designed).");
        $this->newLine();
        $this->comment('Why DuplicateTownSweeper leaves these: it never auto-deletes a PUBLISHED page, and it '
            .'groups by (parent_location_id, townKey) — so a mis-parented pair is split across groups and never '
            .'compared. Resolve a same-market duplicate by taking the extra page down (Operate → Locations) then '
            .'running launchpad:dedupe-town-pages. Cross-market pairs need a human call (real town vs mis-assignment).');

        return self::SUCCESS;
    }

    /** @return string|null|false site id (null = all tenants, false = resolution error) */
    private function resolveSiteId(): string|null|false
    {
        $opt = trim((string) $this->option('site'));
        if ($opt === '') {
            return null;
        }

        $site = Site::withoutGlobalScope(VisibleSiteScope::class)
            ->where('id', $opt)->orWhere('brand_name', $opt)->first();

        if ($site === null) {
            $this->error("No site matches [{$opt}].");

            return false;
        }

        return (string) $site->id;
    }

    /** Three-state index verdict from the durable table (mirrors the Live board): the absent case is honest. */
    private function indexState(Content $page): string
    {
        $row = PageIndexState::withoutGlobalScope(SiteScope::class)->where('content_id', $page->id)->first();
        if ($row === null) {
            return 'not yet checked';
        }

        return $row->isIndexed() ? 'indexed' : ($row->coverage_state !== null && $row->coverage_state !== '' ? "not indexed ({$row->coverage_state})" : 'not indexed');
    }

    private function publicUrl(Content $page): string
    {
        $domain = $this->domains[$page->site_id] ??= Site::withoutGlobalScope(VisibleSiteScope::class)
            ->find($page->site_id)?->domain_url;

        // Canonical URL (PublicUrl — the trailing-slash permalink); shows the nesting shape that settles a
        // pair, e.g. /hoboken-nj/ (flat town) vs /hoboken-nj/hoboken-nj/ (nested under its hub).
        return PublicUrl::forContent($domain, $page) ?? '(no domain)';
    }

    /**
     * The label the URL uses for a location — its hub landing title ("{City}, {ST}"), distinctive, NOT
     * Location.name (the brand, shared across a tenant's locations). Falls back to city/state, then name.
     */
    private function locationLabel(string $locationId): string
    {
        if (isset($this->labels[$locationId])) {
            return $this->labels[$locationId];
        }

        $location = Location::withoutGlobalScope(SiteScope::class)->find($locationId);
        if ($location === null) {
            return $this->labels[$locationId] = "(unknown location {$locationId})";
        }

        $hubTitle = trim((string) Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('location_id', $location->id)
            ->whereNotNull('title')
            ->value('title'));

        if ($hubTitle === '') {
            $cs = $location->cityState();
            $hubTitle = trim(trim((string) $cs['city']).', '.trim((string) $cs['state']), ', ');
        }

        return $this->labels[$locationId] = ($hubTitle !== '' ? $hubTitle : (string) $location->name);
    }

    /** Normalize a town name for matching (drop a trailing ", ST", lower) — mirrors the sweeper + CLI. */
    private function townKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim($name))));
    }
}
