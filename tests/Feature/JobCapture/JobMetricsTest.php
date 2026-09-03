<?php

use App\Enums\IndexCoverageState;
use App\Integrations\Analytics\PageTrafficProvider;
use App\Integrations\SearchConsole\PageQuery;
use App\Integrations\SearchConsole\PageSearchStats;
use App\Integrations\SearchConsole\SearchConsoleProvider;
use App\Integrations\UrlInspection\IndexInspector;
use App\Integrations\UrlInspection\IndexStatus;
use App\JobCapture\Metrics\JobMetrics;
use App\Models\Job;
use App\Models\Site;

it('assembles index + gsc (with queries) + traffic for a job, keyed on /jobs/{slug}', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $job = Job::factory()->create(['site_id' => $site->id, 'post_title' => 'Sump Pump Replacement'])->fresh();

    $path = $job->publicPath();
    $url = $job->publicUrl('https://spg.example');

    $gsc = Mockery::mock(SearchConsoleProvider::class);
    $gsc->shouldReceive('connected')->andReturnTrue();
    $gsc->shouldReceive('pageStats')->with(Mockery::any(), $path)->andReturn(new PageSearchStats(20, 3, 28));
    $gsc->shouldReceive('pageQueries')->with(Mockery::any(), $path)
        ->andReturn([new PageQuery('sump pump replacement newark', 2, 15, 13.3, 8.4)]);

    $idx = Mockery::mock(IndexInspector::class);
    $idx->shouldReceive('connected')->andReturnTrue();
    $idx->shouldReceive('cached')->with(Mockery::any(), $url)
        ->andReturn(new IndexStatus(url: (string) $url, state: IndexCoverageState::Indexed, coverageState: 'Submitted and indexed'));

    $traffic = Mockery::mock(PageTrafficProvider::class);
    $traffic->shouldReceive('connected')->andReturnTrue();
    $traffic->shouldReceive('sessions')->with(Mockery::any(), $path)->andReturn(7);

    $m = (new JobMetrics($gsc, $idx, $traffic))->for($job);

    expect($m['gsc']['impressions'])->toBe(20)
        ->and($m['gsc']['clicks'])->toBe(3)
        ->and($m['gsc']['ctr'])->toBe(15.0)                 // 3/20 → 15.0% (a percentage, not a fraction)
        ->and($m['gsc']['in_google'])->toBeTrue()
        ->and($m['gsc']['queries'][0]['query'])->toBe('sump pump replacement newark')
        ->and($m['index']['indexed'])->toBeTrue()
        ->and($m['index']['coverage_state'])->toBe('Submitted and indexed')
        ->and($m['traffic']['sessions'])->toBe(7);
});

it('degrades to honest pending when the sources are not connected — never a fabricated zero', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $job = Job::factory()->create(['site_id' => $site->id])->fresh();

    $gsc = Mockery::mock(SearchConsoleProvider::class);
    $gsc->shouldReceive('connected')->andReturnFalse();
    $idx = Mockery::mock(IndexInspector::class);
    $idx->shouldReceive('connected')->andReturnFalse();
    $traffic = Mockery::mock(PageTrafficProvider::class);
    $traffic->shouldReceive('connected')->andReturnFalse();

    $m = (new JobMetrics($gsc, $idx, $traffic))->for($job);

    expect($m['gsc']['pending'])->toBe('Connect Search Console')
        ->and($m['gsc']['impressions'])->toBeNull()
        ->and($m['index']['pending'])->toBe('Connect Search Console')
        ->and($m['traffic']['pending'])->toBe('Connect GA4');
});

it('shows "run index audit" / "collecting" when connected but no data is cached yet', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $job = Job::factory()->create(['site_id' => $site->id])->fresh();

    $gsc = Mockery::mock(SearchConsoleProvider::class);
    $gsc->shouldReceive('connected')->andReturnTrue();
    $gsc->shouldReceive('pageStats')->andReturnNull();

    $idx = Mockery::mock(IndexInspector::class);
    $idx->shouldReceive('connected')->andReturnTrue();
    $idx->shouldReceive('cached')->andReturnNull();

    $traffic = Mockery::mock(PageTrafficProvider::class);
    $traffic->shouldReceive('connected')->andReturnTrue();
    $traffic->shouldReceive('sessions')->andReturnNull();

    $m = (new JobMetrics($gsc, $idx, $traffic))->for($job);

    expect($m['index']['pending'])->toBe('Run index audit')
        ->and($m['gsc']['pending'])->toContain('Collecting')
        ->and($m['traffic']['pending'])->toBe('Collecting');
});

it('cacheOnly render reads the WARMED cache only — never the fetching pageStats/pageQueries/sessions', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $job = Job::factory()->create(['site_id' => $site->id, 'post_title' => 'Sump Pump Replacement'])->fresh();
    $path = $job->publicPath();

    $gsc = Mockery::mock(SearchConsoleProvider::class);
    $gsc->shouldReceive('connected')->andReturnTrue();
    $gsc->shouldReceive('pageStatsCached')->with(Mockery::any(), $path)->andReturn(new PageSearchStats(20, 3, 28));
    $gsc->shouldReceive('pageQueriesCached')->with(Mockery::any(), $path)
        ->andReturn([new PageQuery('sump pump replacement newark', 2, 15, 13.3, 8.4)]);
    // The zero-HTTP contract: the fetching variants must NEVER be touched on the render path.
    $gsc->shouldNotReceive('pageStats');
    $gsc->shouldNotReceive('pageQueries');

    $idx = Mockery::mock(IndexInspector::class);
    $idx->shouldReceive('connected')->andReturnTrue();
    $idx->shouldReceive('cached')->andReturnNull();

    $traffic = Mockery::mock(PageTrafficProvider::class);
    $traffic->shouldReceive('connected')->andReturnTrue();
    $traffic->shouldReceive('sessionsCached')->with(Mockery::any(), $path)->andReturn(7);
    $traffic->shouldNotReceive('sessions');

    $m = (new JobMetrics($gsc, $idx, $traffic))->for($job, cacheOnly: true);

    expect($m['gsc']['impressions'])->toBe(20)
        ->and($m['gsc']['queries'][0]['query'])->toBe('sump pump replacement newark')
        ->and($m['traffic']['sessions'])->toBe(7);
});

it('cacheOnly render shows "Refreshing…" (not "Collecting") when connected but nothing is warmed yet', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $job = Job::factory()->create(['site_id' => $site->id])->fresh();

    $gsc = Mockery::mock(SearchConsoleProvider::class);
    $gsc->shouldReceive('connected')->andReturnTrue();
    $gsc->shouldReceive('pageStatsCached')->andReturnNull(); // cache miss
    $gsc->shouldNotReceive('pageStats');

    $idx = Mockery::mock(IndexInspector::class);
    $idx->shouldReceive('connected')->andReturnTrue();
    $idx->shouldReceive('cached')->andReturnNull();

    $traffic = Mockery::mock(PageTrafficProvider::class);
    $traffic->shouldReceive('connected')->andReturnTrue();
    $traffic->shouldReceive('sessionsCached')->andReturnNull(); // cache miss
    $traffic->shouldNotReceive('sessions');

    $m = (new JobMetrics($gsc, $idx, $traffic))->for($job, cacheOnly: true);

    expect($m['gsc']['pending'])->toBe('Refreshing…')
        ->and($m['traffic']['pending'])->toBe('Refreshing…');
});
