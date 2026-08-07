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
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'battery-backup-sump-pump-types', 'title' => 'Battery Backup Sump Pump Types']);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'hoboken-nj', 'title' => 'Hoboken, NJ', 'page_type' => PageType::Location]);

    // GSC inventory: a mix of live, orphaned, and junk URLs.
    lrpDaily($site, 'https://spg.example/sump-pump-repair/', 900);              // live → skipped
    lrpDaily($site, 'https://spg.example/battery-backup-sump-pump-types-5/', 300); // numbered dup
    lrpDaily($site, 'https://spg.example/hoboken/', 200);                       // bare town
    lrpDaily($site, 'https://spg.example/old-repair-writeup/', 150);           // routed by top query
    lrpQuery($site, 'https://spg.example/old-repair-writeup/', 'sump pump repair', 150);
    lrpDaily($site, 'https://spg.example/venice-fl/', 40);                      // out-of-footprint → gone
    lrpDaily($site, 'https://spg.example/mystery-widget-xyz/', 10);            // no signal → unresolved

    $plan = app(LegacyRedirectPlanner::class)->plan($site);

    $byFrom = collect($plan['redirect'])->keyBy('from');
    expect($plan['skipped_live'])->toBe(1)
        ->and($byFrom['/battery-backup-sump-pump-types-5']['to'])->toBe('/battery-backup-sump-pump-types')
        ->and($byFrom['/battery-backup-sump-pump-types-5']['reason'])->toBe('numbered_duplicate')
        ->and($byFrom['/hoboken']['to'])->toBe('/hoboken-nj')
        ->and($byFrom['/hoboken']['reason'])->toBe('town')
        ->and($byFrom['/old-repair-writeup']['to'])->toBe('/sump-pump-repair')
        ->and($byFrom['/old-repair-writeup']['reason'])->toBe('top_query');

    expect(collect($plan['gone'])->pluck('from'))->toContain('/venice-fl');
    expect(collect($plan['gone'])->firstWhere('from', '/venice-fl')['reason'])->toBe('out_of_footprint');
    expect(collect($plan['unresolved'])->pluck('from'))->toContain('/mystery-widget-xyz');

    // Ranked by lost impressions (highest first).
    expect($plan['redirect'][0]['from'])->toBe('/battery-backup-sump-pump-types-5');

    // Apply persists 301 + 410 rows and never creates a redirect FROM a live page.
    $written = app(LegacyRedirectPlanner::class)->apply($site, $plan);
    expect($written)->toBe(count($plan['redirect']) + count($plan['gone']));

    $rows = Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()->keyBy('from_url');
    expect($rows->has('/sump-pump-repair'))->toBeFalse()               // live page never shadowed
        ->and((int) $rows['/hoboken']->code)->toBe(301)
        ->and((int) $rows['/venice-fl']->code)->toBe(410);
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
