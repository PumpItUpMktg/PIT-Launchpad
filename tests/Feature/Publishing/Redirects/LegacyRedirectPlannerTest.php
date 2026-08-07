<?php

use App\Analytics\Gsc\Grain;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Redirects\LegacyRedirectPlanner;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function lrpDaily(Site $site, string $url, int $impressions): void
{
    DB::table('gsc_url_daily')->insert([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'grain_hash' => Grain::hash([$site->id, '2025-06-01', $url]),
        'date' => '2025-06-01',
        'url' => $url,
        'impressions' => $impressions,
        'clicks' => 0,
        'ctr' => 0,
        'position' => 5,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function lrpQuery(Site $site, string $url, string $query, int $impressions): void
{
    DB::table('gsc_url_query_daily')->insert([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'grain_hash' => Grain::hash([$site->id, '2025-06-01', $url, $query, 'usa', 'DESKTOP']),
        'date' => '2025-06-01',
        'url' => $url,
        'query' => $query,
        'country' => 'usa',
        'device' => 'DESKTOP',
        'impressions' => $impressions,
        'clicks' => 0,
        'ctr' => 0,
        'position' => 5,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('routes the legacy GSC inventory to 301/410/unresolved and never shadows a live page', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    CurrentSite::set($site->id);

    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair']);

    // Live published pages.
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'sump-pump-repair', 'title' => 'Sump Pump Repair', 'target_keyword_id' => $kw->id]);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'sump-pump-installation', 'title' => 'Sump Pump Installation']);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'battery-backup-sump-pump-types', 'title' => 'Battery Backup Sump Pump Types']);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'hoboken-nj', 'title' => 'Hoboken, NJ', 'page_type' => PageType::Location]);

    // GSC inventory: a mix of live, orphaned, dup, and junk URLs.
    lrpDaily($site, 'https://spg.example/sump-pump-repair/', 900);                       // live → skipped
    lrpDaily($site, 'https://spg.example/sump-pump-installation-cost-breakdown-3/', 500); // dup, base NOT live → routes via base slug overlap
    lrpDaily($site, 'https://spg.example/battery-backup-sump-pump-types-5/', 300);       // numbered dup, base live
    lrpDaily($site, 'https://spg.example/hoboken/', 200);                                // bare town
    lrpDaily($site, 'https://spg.example/old-repair-writeup/', 150);                     // routed by top query
    lrpQuery($site, 'https://spg.example/old-repair-writeup/', 'sump pump repair', 150);
    lrpDaily($site, 'https://spg.example/water-damage-drying-near-me/', 100);            // "near-me" must NOT 410 → unresolved
    lrpDaily($site, 'https://spg.example/venice-fl/', 40);                               // no confident target → unresolved
    lrpDaily($site, 'https://spg.example/mystery-widget-xyz/', 10);                     // no signal → unresolved

    $plan = app(LegacyRedirectPlanner::class)->plan($site);

    $byFrom = collect($plan['redirect'])->keyBy('from');
    expect($plan['skipped_live'])->toBe(1)
        ->and($byFrom['/sump-pump-installation-cost-breakdown-3']['to'])->toBe('/sump-pump-installation')
        ->and($byFrom['/sump-pump-installation-cost-breakdown-3']['reason'])->toBe('numbered_duplicate')
        ->and($byFrom['/battery-backup-sump-pump-types-5']['to'])->toBe('/battery-backup-sump-pump-types')
        ->and($byFrom['/battery-backup-sump-pump-types-5']['reason'])->toBe('numbered_duplicate')
        ->and($byFrom['/hoboken']['to'])->toBe('/hoboken-nj')
        ->and($byFrom['/hoboken']['reason'])->toBe('town')
        ->and($byFrom['/old-repair-writeup']['to'])->toBe('/sump-pump-repair')
        ->and($byFrom['/old-repair-writeup']['reason'])->toBe('top_query');

    // No auto-410: "near-me" and unmatched geo-ish slugs are surfaced, never guessed as gone.
    expect($plan['gone'])->toBe([]);
    $unresolvedFrom = collect($plan['unresolved'])->pluck('from');
    expect($unresolvedFrom)->toContain('/water-damage-drying-near-me')
        ->and($unresolvedFrom)->toContain('/venice-fl')
        ->and($unresolvedFrom)->toContain('/mystery-widget-xyz');

    // Ranked by lost impressions (highest first).
    expect($plan['redirect'][0]['from'])->toBe('/sump-pump-installation-cost-breakdown-3');

    // Apply persists the 301 rows and never creates a redirect FROM a live page.
    $written = app(LegacyRedirectPlanner::class)->apply($site, $plan);
    expect($written)->toBe(count($plan['redirect']));

    $rows = Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()->keyBy('from_url');
    expect($rows->has('/sump-pump-repair'))->toBeFalse()               // live page never shadowed
        ->and($rows->has('/water-damage-drying-near-me'))->toBeFalse() // unresolved never written
        ->and((int) $rows['/hoboken']->code)->toBe(301);
});

it('is idempotent and skips URLs that already have a redirect', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG2', 'domain_url' => 'https://spg2.example']);
    CurrentSite::set($site->id);

    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'hoboken-nj', 'title' => 'Hoboken, NJ', 'page_type' => PageType::Location]);
    lrpDaily($site, 'https://spg2.example/hoboken/', 200);

    $planner = app(LegacyRedirectPlanner::class);
    $planner->apply($site, $planner->plan($site));
    // Second pass: the URL now has a redirect, so it drops out of the plan.
    $plan2 = $planner->plan($site);

    expect($plan2['redirect'])->toBe([])
        ->and(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1);
});
