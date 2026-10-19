<?php

use App\Backlinks\BacklinkSyncer;
use App\Integrations\Backlinks\BacklinkPage;
use App\Integrations\Backlinks\BacklinkProvider;
use App\Integrations\Backlinks\BacklinkRow;
use App\Integrations\Backlinks\BacklinkSummary;
use App\Models\BacklinkSyncRun;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteBacklink;

/** A provider whose pages are scripted; `$calls` records every (target, after) it was asked for. */
function scriptedBacklinks(array $pages, array &$calls, ?Throwable $fail = null): BacklinkProvider
{
    return new class($pages, $calls, $fail) implements BacklinkProvider
    {
        public function __construct(private array $pages, public array &$calls, private ?Throwable $fail) {}

        public function summary(string $target): BacklinkSummary
        {
            if ($this->fail !== null) {
                throw $this->fail;
            }

            return new BacklinkSummary(backlinks: 20147, referringDomains: 3482, brokenBacklinks: 1864, rank: 57);
        }

        public function referringDomains(string $target, int $limit = 1000, ?string $after = null): BacklinkPage
        {
            $this->calls[] = [$target, $after];

            return $this->pages[$after ?? 'first'];
        }

        public function domainIntersection(array $targets, array $exclude, int $limit = 300): array
        {
            return [];
        }
    };
}

function blRow(string $domain, string $urlTo, int $rank = 50, bool $broken = false): BacklinkRow
{
    return new BacklinkRow(domainFrom: $domain, urlFrom: "https://{$domain}/listing", urlTo: $urlTo, domainFromRank: $rank, pageFromRank: null, spamScore: 0, dofollow: true, isBroken: $broken, anchor: null, firstSeen: '2026-01-01', lastSeen: '2026-10-01');
}

it('stores one row per referring domain across pages, normalizes the target path, and records the run', function () {
    $site = Site::factory()->create(['domain_url' => 'https://www.sumppumpgurus.com']);
    $calls = [];
    $provider = scriptedBacklinks([
        'first' => new BacklinkPage([blRow('yelp.com', 'https://sumppumpgurus.com/Hoboken-NJ/'), blRow('xpr.media', 'https://sumppumpgurus.com/')], 3, 'tok-2'),
        'tok-2' => new BacklinkPage([blRow('mapquest.com', 'https://www.sumppumpgurus.com/hoboken', 82, broken: true)], 3, null),
    ], $calls);

    $run = (new BacklinkSyncer($provider))->run($site);

    expect($run->status)->toBe(BacklinkSyncRun::COMPLETE)
        ->and($run->target)->toBe('sumppumpgurus.com') // host only, no www
        ->and($run->rows_stored)->toBe(3)
        ->and($run->pages_fetched)->toBe(2)
        ->and($run->referring_domains)->toBe(3482)
        ->and($run->rank)->toBe(57)
        ->and($calls)->toBe([['sumppumpgurus.com', null], ['sumppumpgurus.com', 'tok-2']]);

    $rows = SiteBacklink::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()->keyBy('domain_from');
    expect($rows)->toHaveCount(3)
        ->and($rows['yelp.com']->target_path)->toBe('/hoboken-nj')   // lowercased, no trailing slash
        ->and($rows['xpr.media']->target_path)->toBe('/')
        ->and($rows['mapquest.com']->target_path)->toBe('/hoboken')
        ->and($rows['mapquest.com']->is_broken)->toBeTrue()
        ->and($rows['mapquest.com']->domain_from_rank)->toBe(82);
});

it('replaces the rows whole on the next sync, and a failed sync keeps the previous rows', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sumppumpgurus.com']);
    $calls = [];
    (new BacklinkSyncer(scriptedBacklinks(['first' => new BacklinkPage([blRow('yelp.com', 'https://sumppumpgurus.com/a'), blRow('gone.example', 'https://sumppumpgurus.com/b')], 2, null)], $calls)))->run($site);
    (new BacklinkSyncer(scriptedBacklinks(['first' => new BacklinkPage([blRow('yelp.com', 'https://sumppumpgurus.com/a2')], 1, null)], $calls)))->run($site);

    $rows = SiteBacklink::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
    expect($rows)->toHaveCount(1)->and($rows->first()->target_path)->toBe('/a2');

    $failed = (new BacklinkSyncer(scriptedBacklinks([], $calls, new RuntimeException('quota exhausted'))))->run($site);

    expect($failed->status)->toBe(BacklinkSyncRun::FAILED)
        ->and($failed->error)->toBe('quota exhausted')
        ->and(SiteBacklink::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1);
});

it('stops at the configured page cap', function () {
    config(['launchpad.backlinks.max_pages' => 1]);
    $site = Site::factory()->create(['domain_url' => 'https://sumppumpgurus.com']);
    $calls = [];
    $run = (new BacklinkSyncer(scriptedBacklinks(['first' => new BacklinkPage([blRow('yelp.com', 'https://sumppumpgurus.com/')], 9, 'tok-2')], $calls)))->run($site);

    expect($run->pages_fetched)->toBe(1)->and($calls)->toHaveCount(1);
});

it('the command syncs one site by brand name and reports the totals', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://sumppumpgurus.com']);
    $calls = [];
    app()->instance(BacklinkProvider::class, scriptedBacklinks(['first' => new BacklinkPage([blRow('yelp.com', 'https://sumppumpgurus.com/')], 1, null)], $calls));

    $this->artisan('launchpad:sync-backlinks', ['--site' => 'Sump Pump Gurus'])
        ->expectsOutputToContain('3,482 referring domains in the index, 20,147 backlinks, 1,864 broken; stored 1 domain rows in 1 page(s); authority 57.')
        ->assertSuccessful();

    expect(BacklinkSyncRun::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1);
});
