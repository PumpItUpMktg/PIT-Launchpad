<?php

use App\Enums\PipelineTrigger;
use App\Integrations\LocalGrid\LocalGridProvider;
use App\Integrations\LocalGrid\MockLocalGridProvider;
use App\Integrations\Serp\MockSerpProvider;
use App\Integrations\Serp\SerpProvider;
use App\Integrations\Serp\SerpResult;
use App\Integrations\Serp\SerpResultSet;
use App\KeywordGenerator\Pipeline\RefreshKeywordPipelines;
use App\KeywordGenerator\Pipeline\SitePipelineRefresher;
use App\Models\Keyword;
use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\Log;

/** A scored keyword whose recent score means discovery is NOT due (isolates tracking). */
function s5TrackedKeyword(Site $site, string $query): Keyword
{
    return Keyword::factory()->create([
        'site_id' => $site->id,
        'query' => $query,
        'status' => 'scored',
        'opportunity_score' => 5.0,
    ]);
}

function bindMockProviders(MockSerpProvider $serp, ?MockLocalGridProvider $grid = null): void
{
    app()->instance(SerpProvider::class, $serp);
    app()->instance(LocalGridProvider::class, $grid ?? new MockLocalGridProvider);
}

function snapshotCount(Site $site): int
{
    return PositionSnapshot::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count();
}

it('the scheduled driver tracks engine-eligible sites and skips the rest', function () {
    $serp = (new MockSerpProvider)
        ->setResults('plumber austin', new SerpResultSet('plumber austin', [
            new SerpResult(3, 'https://acme.com/plumber', 'acme.com'),
        ]));
    bindMockProviders($serp);

    $active = Site::factory()->create(['status' => 'active', 'domain_url' => 'https://acme.com']);
    s5TrackedKeyword($active, 'plumber austin');

    $suspended = Site::factory()->create(['status' => 'suspended', 'domain_url' => 'https://zzz.com']);
    s5TrackedKeyword($suspended, 'plumber austin');

    (new RefreshKeywordPipelines)->handle(app(SitePipelineRefresher::class));

    expect(snapshotCount($active))->toBe(1)       // organic snapshot recorded
        ->and(snapshotCount($suspended))->toBe(0); // ineligible — untouched
});

it('records the own-domain organic rank and skips queries it does not rank for', function () {
    $serp = (new MockSerpProvider)
        ->setResults('ranked', new SerpResultSet('ranked', [
            new SerpResult(7, 'https://acme.com/a', 'acme.com'),
            new SerpResult(1, 'https://rival.com', 'rival.com'),
        ]))
        ->setResults('unranked', new SerpResultSet('unranked', [
            new SerpResult(1, 'https://rival.com', 'rival.com'),
        ]));
    bindMockProviders($serp);

    $site = Site::factory()->create(['status' => 'active', 'domain_url' => 'https://acme.com']);
    s5TrackedKeyword($site, 'ranked');
    s5TrackedKeyword($site, 'unranked');

    app(SitePipelineRefresher::class)->refresh($site, PipelineTrigger::Scheduled);

    $snaps = PositionSnapshot::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
    expect($snaps)->toHaveCount(1)
        ->and((int) $snaps[0]->rank)->toBe(7); // own best rank, not the rival's
});

it('cadence-dedup skips a site with a recent snapshot and the operator force bypasses it', function () {
    $serp = (new MockSerpProvider)->setResults('plumber', new SerpResultSet('plumber', [
        new SerpResult(4, 'https://acme.com/p', 'acme.com'),
    ]));
    bindMockProviders($serp);

    $site = Site::factory()->create(['status' => 'active', 'domain_url' => 'https://acme.com']);
    $keyword = s5TrackedKeyword($site, 'plumber');
    // A fresh snapshot → tracking is within the cadence window.
    PositionSnapshot::factory()->create(['site_id' => $site->id, 'keyword_id' => $keyword->id, 'captured_at' => now()]);

    // Scheduled run respects the window → tracking is skipped, no new snapshot.
    $scheduled = app(SitePipelineRefresher::class)->refresh($site, PipelineTrigger::Scheduled);
    expect($scheduled->trackingRan)->toBeFalse()
        ->and(snapshotCount($site))->toBe(1);

    // Operator force bypasses the window → tracking runs regardless.
    $manual = app(SitePipelineRefresher::class)->refresh($site, PipelineTrigger::Manual, force: true);
    expect($manual->trackingRan)->toBeTrue();
});

it('isolates per-site failures — one site throwing does not abort the run', function () {
    $serp = new class extends MockSerpProvider
    {
        public function results(string $query): SerpResultSet
        {
            if ($query === 'boom') {
                throw new RuntimeException('SERP provider down');
            }

            return parent::results($query);
        }
    };
    $serp->setResults('ok', new SerpResultSet('ok', [new SerpResult(2, 'https://good.com/x', 'good.com')]));
    bindMockProviders($serp);

    $broken = Site::factory()->create(['status' => 'active', 'domain_url' => 'https://bad.com']);
    s5TrackedKeyword($broken, 'boom');

    $healthy = Site::factory()->create(['status' => 'active', 'domain_url' => 'https://good.com']);
    s5TrackedKeyword($healthy, 'ok');

    (new RefreshKeywordPipelines)->handle(app(SitePipelineRefresher::class));

    // The healthy site still got its snapshot despite the other throwing.
    expect(snapshotCount($healthy))->toBe(1)
        ->and(snapshotCount($broken))->toBe(0);
});

it('logs run-provenance with the trigger', function () {
    Log::spy();
    bindMockProviders(new MockSerpProvider);

    $site = Site::factory()->create(['status' => 'active', 'domain_url' => 'https://acme.com']);

    app(SitePipelineRefresher::class)->refresh($site, PipelineTrigger::Scheduled);

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) {
        return $message === '§5 pipeline refresh' && $context['trigger'] === 'scheduled';
    })->once();
});
