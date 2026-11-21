<?php

use App\ContentEngine\Questions\RefreshCrawlCheck;
use App\Integrations\UrlInspection\IndexInspector;
use App\Integrations\UrlInspection\IndexStatus;
use App\Jobs\ReinspectRefreshedPost;
use App\Models\Content;
use App\Models\PageIndexState;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

function crawlPost(Site $site, string $republished = '2026-11-08T15:00:00+00:00', array $extra = []): Content
{
    return Content::factory()->post()->published()->create(array_merge([
        'site_id' => $site->id, 'slug' => 'cost', 'title' => 'Cost', 'body' => '<p>x</p>',
        'meta' => ['question_refresh' => ['query' => 'q', 'refreshed_at' => '2026-11-07T10:00:00+00:00', 'republished_at' => $republished]],
    ], $extra));
}

function crawlStatus(?string $crawled, string $inspected, string $state = 'indexed'): IndexStatus
{
    return IndexStatus::fromArray(['url' => 'https://spg.example/cost/', 'state' => $state, 'coverage_state' => 'Submitted and indexed', 'verdict' => 'PASS',
        'last_crawled_at' => $crawled, 'inspected_at' => $inspected, 'result_link' => 'https://search.google.com/search-console/inspect?x=1']);
}

function inspectorWith(?IndexStatus $cached, ?IndexStatus $fresh = null, bool $connected = true): IndexInspector
{
    $m = Mockery::mock(IndexInspector::class);
    $m->shouldReceive('cached')->andReturn($cached);
    $m->shouldReceive('connected')->andReturn($connected);
    $m->shouldReceive('inspect')->andReturn($fresh);

    return $m;
}

it('says crawled since refresh when Google\'s last crawl is after the republish, not crawled when it is before, unchecked when the inspection itself predates the refresh, none when never asked', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $post = crawlPost($site);
    $now = Carbon::parse('2026-11-21 09:00:00');

    $crawled = (new RefreshCrawlCheck(inspectorWith(crawlStatus('2026-11-12T03:00:00Z', '2026-11-19T04:00:00Z'))))->for($site, $post, $now);
    expect($crawled['state'])->toBe('crawled')->and($crawled['label'])->toBe('Crawled since refresh · Nov 12')->and($crawled['since'])->toBe('2026-11-08')
        ->and($crawled['detail'])->toContain('after the refreshed content went live on Nov 8')->and($crawled['verdict'])->toBe('PASS');

    $notYet = (new RefreshCrawlCheck(inspectorWith(crawlStatus('2026-10-30T03:00:00Z', '2026-11-19T04:00:00Z'))))->for($site, $post, $now);
    expect($notYet['state'])->toBe('not_crawled')->and($notYet['label'])->toBe('Not crawled since refresh')->and($notYet['detail'])->toContain('last crawl is Oct 30');

    $stale = (new RefreshCrawlCheck(inspectorWith(crawlStatus('2026-10-30T03:00:00Z', '2026-11-02T04:00:00Z'))))->for($site, $post, $now);
    expect($stale['state'])->toBe('unchecked')->and($stale['label'])->toBe('Not checked since refresh')->and($stale['detail'])->toContain('predates the refresh');

    // Nothing cached: the stored row counts (and the freshest of the two wins).
    PageIndexState::create(['site_id' => $site->id, 'content_id' => $post->id, 'origin' => 'content', 'url' => 'https://spg.example/cost/', 'url_normalized' => 'spg.example/cost',
        'coverage_state' => 'Submitted and indexed', 'index_verdict' => 'PASS', 'last_crawled_at' => '2026-11-15 02:00:00', 'last_inspected_at' => '2026-11-20 05:00:00']);
    $fromRow = (new RefreshCrawlCheck(inspectorWith(null)))->for($site, $post, $now);
    expect($fromRow['state'])->toBe('crawled')->and($fromRow['last_crawled_at'])->toBe('2026-11-15')->and($fromRow['last_inspected_at'])->toBe('2026-11-20');

    $never = crawlPost($site, extra: ['slug' => 'never']);
    expect((new RefreshCrawlCheck(inspectorWith(null)))->for($site, $never, $now)['state'])->toBe('none');
    expect((new RefreshCrawlCheck(inspectorWith(null)))->for($site, Content::factory()->post()->published()->create(['site_id' => $site->id, 'slug' => 'plain', 'meta' => []]), $now))->toBeNull();
});

it('reinspect() asks Google again, writes the verdict and crawl time to the index-state row and the post, and the job carries the "checking" state', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example', 'gsc_property' => 'sc-domain:spg.example']);
    $post = crawlPost($site);
    $fresh = crawlStatus('2026-11-14T06:00:00Z', '2026-11-21T08:00:00Z');
    $inspector = inspectorWith(null, $fresh);
    app()->instance(IndexInspector::class, $inspector);

    Queue::fake();
    ReinspectRefreshedPost::enqueue($post);
    Queue::assertPushed(ReinspectRefreshedPost::class, fn (ReinspectRefreshedPost $j) => $j->contentId === (string) $post->id);
    $check = new RefreshCrawlCheck($inspector);
    expect($check->for($site, $post->fresh(), Carbon::parse('2026-11-21 08:30:00'))['checking'])->toBeTrue();

    (new ReinspectRefreshedPost($post->id))->handle($check);

    $row = PageIndexState::withoutGlobalScopes()->where('content_id', $post->id)->sole();
    expect($row->index_verdict)->toBe('PASS')->and($row->last_crawled_at->toIso8601String())->toBe('2026-11-14T06:00:00+00:00')
        ->and($row->last_inspected_at->toIso8601String())->toBe('2026-11-21T08:00:00+00:00')->and($row->url_normalized)->toBe('spg.example/cost');
    expect($post->fresh()->meta['refresh_crawl']['checked_at'])->not->toBeNull();
    $after = $check->for($site, $post->fresh(), Carbon::parse('2026-11-21 09:00:00'));
    expect($after['state'])->toBe('crawled')->and($after['checking'])->toBeFalse();

    // Not connected: nothing asked, nothing written.
    $offline = crawlPost($site, extra: ['slug' => 'offline']);
    expect((new RefreshCrawlCheck(inspectorWith(null, null, connected: false)))->reinspect($site, $offline))->toBeNull()
        ->and(PageIndexState::withoutGlobalScopes()->where('content_id', $offline->id)->exists())->toBeFalse();
});
