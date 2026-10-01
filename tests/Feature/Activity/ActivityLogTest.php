<?php

use App\Activity\ActivityLog;
use App\Activity\ActivityPeriod;
use App\Activity\ActivityRecorder;
use App\Activity\MonthlySnapshots;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Activity;
use App\Filament\Pages\ActivityLogPage;
use App\Models\ClientMilestone;
use App\Models\Content;
use App\Models\Conversion;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Models\TownRankScan;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ClientHarness;

afterEach(fn () => Carbon::setTestNow());

/** A site with a month of work in September 2026, today being 15 Oct 2026. */
function activitySite(): Site
{
    Carbon::setTestNow(Carbon::create(2026, 10, 15, 12));
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'created_at' => Carbon::create(2026, 8, 20)]);
    $office = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office']);
    foreach ([['Warren, NJ', '2026-09-03'], ['Hackettstown, NJ', '2026-09-03'], ['Blairstown, NJ', '2026-09-10']] as [$title, $on]) {
        Content::factory()->create([
            'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Published,
            'location_id' => null, 'parent_location_id' => $office->id, 'title' => $title, 'slug' => strtolower(str_replace([', ', ' '], ['-', '-'], $title)),
            'published_at' => Carbon::parse($on.' 10:00'), 'wp_post_id' => 1,
        ]);
    }
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published, 'title' => 'Why pits fail in spring',
        'slug' => 'why-pits-fail', 'published_at' => Carbon::parse('2026-09-10 14:00'), 'wp_post_id' => 2,
    ]);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service', 'track_town_rank' => true]);
    TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'complete', 'points_count' => 722, 'found_count' => 200, 'scanned_at' => Carbon::parse('2026-09-14 06:00')]);
    PageIndexState::create(['site_id' => $site->id, 'url' => 'https://spg.example/warren-nj/', 'url_normalized' => 'https://spg.example/warren-nj/', 'indexed_at' => Carbon::parse('2026-09-20 03:00')]);
    ClientMilestone::create(['site_id' => $site->id, 'key' => 'first_top10_keyword', 'occurred_on' => '2026-09-22', 'payload' => ['query' => 'sump pump service'], 'is_client_visible' => true]);
    Conversion::factory()->create(['site_id' => $site->id, 'count' => 3, 'occurred_at' => Carbon::parse('2026-09-25 09:00')]);
    Conversion::factory()->create(['site_id' => $site->id, 'count' => 1, 'occurred_at' => Carbon::parse('2026-08-25 09:00')]);
    app(ActivityRecorder::class)->record($site->id, ActivityRecorder::REPUSH, 'Re-published 3 pages to the website', ['pages' => 3], clientVisible: true);
    app(ActivityRecorder::class)->record($site->id, ActivityRecorder::PRIORITY_KEYWORD, '“sump pump service” made town-page priority #1');
    DB::table('activity_events')->update(['occurred_at' => '2026-09-28 11:00:00']);
    // The metric spine: indexed pages 10 → 12 over September, impressions 100/day in Aug, 150/day in Sep.
    foreach ([['2026-08-31', 10], ['2026-09-30', 12]] as [$on, $v]) {
        DB::table('metric_snapshots')->insert(['id' => (string) Str::ulid(), 'site_id' => $site->id, 'provider' => 'index', 'metric_key' => 'pages_indexed', 'dimension_type' => 'site', 'dimension_value' => '', 'period_grain' => 'day', 'period_date' => $on, 'value_numeric' => $v, 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['2026-08-10' => 100, '2026-09-10' => 150] as $on => $v) {
        DB::table('metric_snapshots')->insert(['id' => (string) Str::ulid(), 'site_id' => $site->id, 'provider' => 'gsc', 'metric_key' => 'impressions', 'dimension_type' => 'site', 'dimension_value' => '', 'period_grain' => 'day', 'period_date' => $on, 'value_numeric' => $v, 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    return $site;
}

it('derives the month\'s timeline, headline and metric movement from the records the work wrote', function () {
    $site = activitySite();

    $report = app(ActivityLog::class)->for($site, ActivityPeriod::month('2026-09'));
    $summaries = collect($report['timeline'])->flatMap(fn (array $d) => array_map(fn (array $e) => $d['date'].' '.$e['summary'], $d['entries']))->all();

    expect($report['period']['label'])->toBe('September 2026')
        ->and($summaries)->toContain('2026-09-03 Published 2 town pages')
        ->toContain('2026-09-10 Published 1 town page, 1 post')
        ->toContain('2026-09-14 Town Rank: 1 keyword scanned across 722 town searches')
        ->toContain('2026-09-20 Google indexed 1 page')
        ->toContain('2026-09-22 Milestone: first page-one keyword — “sump pump service”')
        ->toContain('2026-09-28 Re-published 3 pages to the website')
        ->toContain('2026-09-28 “sump pump service” made town-page priority #1')
        ->and($report['timeline'][0]['date'])->toBe('2026-09-28')   // newest day first
        ->and($report['headline'])->toMatchArray(['pages_published' => 3, 'posts_published' => 1, 'pages_indexed' => 1, 'town_scans' => 722, 'leads' => 3])
        ->and($report['metrics']['pages_live'])->toMatchArray(['start' => 0, 'end' => 4, 'delta' => 4])
        ->and($report['metrics']['pages_indexed'])->toMatchArray(['start' => 10, 'end' => 12, 'delta' => 2])
        ->and($report['metrics']['impressions'])->toMatchArray(['start' => 100, 'end' => 150, 'delta' => 50])
        ->and($report['metrics']['leads'])->toMatchArray(['start' => 1, 'end' => 3, 'delta' => 2])
        ->and($report['metrics']['keywords_top10']['end'])->toBeNull();   // never synced → absent, not zero

    // The client view keeps what was done for them and drops the operator's own housekeeping.
    $client = app(ActivityLog::class)->for($site, ActivityPeriod::month('2026-09'), clientView: true);
    $clientSummaries = collect($client['timeline'])->flatMap(fn (array $d) => array_column($d['entries'], 'summary'))->all();
    expect($clientSummaries)->toContain('Published 2 town pages')->toContain('Re-published 3 pages to the website')
        ->not->toContain('Town Rank: 1 keyword scanned across 722 town searches')
        ->not->toContain('“sump pump service” made town-page priority #1');
});

it('freezes a closed month once, backfills every month since the site began, and lists progress with the open month live', function () {
    $site = activitySite();
    $snapshots = app(MonthlySnapshots::class);

    expect($snapshots->close($site, Carbon::create(2026, 10, 1)))->toBeNull();   // October is still open

    $written = $snapshots->backfill($site);
    expect($written)->toBe(['2026-08', '2026-09'])
        ->and($snapshots->backfill($site))->toBe([]);

    $sep = SiteMonthlySnapshot::withoutGlobalScopes()->where('site_id', $site->id)->whereDate('month', '2026-09-01')->firstOrFail();
    expect($sep->counts['pages_published'])->toBe(3)->and($sep->metrics['pages_indexed']['end'])->toBe(12)->and($sep->timeline_entries)->toBe(7);

    // Frozen: a page published later with a September date does not change the closed month…
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published, 'title' => 'Late', 'slug' => 'late', 'published_at' => Carbon::parse('2026-09-11 09:00'), 'wp_post_id' => 9]);
    expect($snapshots->close($site, Carbon::create(2026, 9, 1))->counts['posts_published'])->toBe(1)
        // …unless explicitly rebuilt.
        ->and($snapshots->close($site, Carbon::create(2026, 9, 1), rebuild: true)->counts['posts_published'])->toBe(2);

    $progress = $snapshots->progress($site);
    expect(array_column($progress, 'label'))->toBe(['Aug 2026', 'Sep 2026', 'Oct 2026'])
        ->and($progress[2]['open'])->toBeTrue()
        ->and($progress[1]['open'])->toBeFalse();

    $this->artisan('launchpad:activity-snapshot', ['--site' => 'Sump Pump Gurus'])
        ->expectsOutputToContain('every closed month already frozen')
        ->assertSuccessful();
});

it('renders the operator Activity page with the timeline, the movement, and the progress tab', function () {
    $site = activitySite();
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    app(ActiveTenant::class)->set($site->id);
    app(MonthlySnapshots::class)->backfill($site);

    Livewire::test(ActivityLogPage::class)
        ->set('period', '2026-09')
        ->assertOk()
        ->assertSee('What was completed — September 2026')
        ->assertSee('Published 2 town pages')
        ->assertSee('Town Rank: 1 keyword scanned across 722 town searches')
        ->assertSee('Pages indexed')
        ->assertSeeHtml('10 → 12')
        ->call('setTab', 'progress')
        ->assertSee('Progress by month')
        ->assertSee('Sep 2026')
        ->assertSee('Oct 2026 (open)');
});

it('renders the client Activity page without the operator-only entries', function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 15, 12));
    ['user' => $client, 'site' => $site] = ClientHarness::make();
    Filament::setCurrentPanel('client');
    $this->actingAs($client);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published, 'title' => 'A post', 'slug' => 'a-post', 'published_at' => Carbon::parse('2026-10-02 09:00'), 'wp_post_id' => 3]);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service', 'track_town_rank' => true]);
    TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'complete', 'points_count' => 50, 'found_count' => 10, 'scanned_at' => Carbon::parse('2026-10-03 06:00')]);

    Livewire::test(Activity::class)
        ->assertOk()
        ->assertSee('Published 1 post')
        ->assertDontSee('Town Rank: 1 keyword scanned');
});
