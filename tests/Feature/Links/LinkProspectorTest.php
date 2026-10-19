<?php

use App\Enums\LinkProspectKind;
use App\Enums\LinkProspectStatus;
use App\Integrations\Backlinks\BacklinkPage;
use App\Integrations\Backlinks\BacklinkProvider;
use App\Integrations\Backlinks\BacklinkSummary;
use App\Integrations\Backlinks\IntersectRow;
use App\Integrations\DataForSeo\DataForSeoException;
use App\KeywordGenerator\Beatability\CompetitorClassifier;
use App\Links\CompetitorTargets;
use App\Links\LinkProspector;
use App\Models\Directory;
use App\Models\Keyword;
use App\Models\LinkProspect;
use App\Models\LinkProspectRun;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use Illuminate\Support\Carbon;

function intersectProvider(array $rows, array &$asked): BacklinkProvider
{
    return new class($rows, $asked) implements BacklinkProvider
    {
        public function __construct(private array $rows, public array &$asked) {}

        public function summary(string $target): BacklinkSummary
        {
            return new BacklinkSummary(0, 0, 0, null);
        }

        public function referringDomains(string $target, int $limit = 1000, ?string $after = null): BacklinkPage
        {
            return new BacklinkPage([], 0, null);
        }

        public function domainIntersection(array $targets, array $exclude, int $limit = 300): array
        {
            $this->asked[] = [$targets, $exclude, $limit];

            return $this->rows;
        }
    };
}

function prospectSite(): Site
{
    $site = Site::factory()->create(['domain_url' => 'https://sumppumpgurus.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump installation', 'town_priority_rank' => 1]);
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'complete', 'points_count' => 1, 'found_count' => 0, 'scanned_at' => Carbon::parse('2026-10-01')]);
    TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'label' => 'Hoboken', 'lat' => 40.7, 'lng' => -74.0, 'query' => 'q', 'rank' => null, 'collected_at' => Carbon::parse('2026-10-01'),
        'top_results' => [['position' => 1, 'url' => 'https://rivalplumbing.com/', 'domain' => 'rivalplumbing.com'], ['position' => 2, 'url' => 'https://dryguys.com/', 'domain' => 'dryguys.com']]]);

    return $site;
}

it('keeps the linking domains worth a human\'s time as prospects, classified, and asks about the competitors Town Rank found', function () {
    $site = prospectSite();
    Directory::factory()->create(['domain' => 'hotfrog.com']);
    $asked = [];
    $rows = [
        new IntersectRow('bucks-chamber.org', 41, 0, ['rivalplumbing.com', 'dryguys.com'], '2023-01-05'),
        new IntersectRow('hotfrog.com', 78, 0, ['dryguys.com'], null),             // catalog directory
        new IntersectRow('yelp.com', 93, 0, ['rivalplumbing.com'], null),         // aggregator, not in the catalog
        new IntersectRow('patch.com', 70, 0, ['rivalplumbing.com'], null),        // editorial? (not in the classifier lists → local)
        new IntersectRow('spamdir.example', 60, 45, ['dryguys.com'], null),       // at the spam cap — dropped
        new IntersectRow('tiny.example', 1, 0, ['dryguys.com'], null),            // below the authority floor — dropped
        new IntersectRow('sumppumpgurus.com', 57, 0, ['dryguys.com'], null),      // us — dropped
    ];

    $r = (new LinkProspector(intersectProvider($rows, $asked), app(CompetitorTargets::class), app(CompetitorClassifier::class)))->run($site);

    expect($asked)->toBe([[['dryguys.com', 'rivalplumbing.com'], ['sumppumpgurus.com'], 1000]])
        ->and($r['returned'])->toBe(7)->and($r['kept'])->toBe(4)->and($r['new'])->toBe(4)->and($r['refreshed'])->toBe(0)
        ->and($r['dropped_spam'])->toBe(1)->and($r['dropped_rank'])->toBe(1);

    $p = LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()->keyBy('domain');
    expect($p)->toHaveCount(4)
        ->and($p['bucks-chamber.org']->competitor_count)->toBe(2)
        ->and($p['bucks-chamber.org']->competitors)->toBe(['rivalplumbing.com', 'dryguys.com'])
        ->and($p['bucks-chamber.org']->kind)->toBe(LinkProspectKind::Local)
        ->and($p['bucks-chamber.org']->status)->toBe(LinkProspectStatus::New)
        ->and($p['bucks-chamber.org']->first_seen?->toDateString())->toBe('2023-01-05')
        ->and($p['hotfrog.com']->kind)->toBe(LinkProspectKind::Directory)
        ->and($p['yelp.com']->kind)->toBe(LinkProspectKind::Directory)
        ->and($p)->not->toHaveKey('spamdir.example')
        ->and($p)->not->toHaveKey('tiny.example')
        ->and($p)->not->toHaveKey('sumppumpgurus.com');
});

it('refreshes the index fields on a re-run and keeps the operator\'s status and notes', function () {
    $site = prospectSite();
    $asked = [];
    $first = (new LinkProspector(intersectProvider([new IntersectRow('bucks-chamber.org', 41, 0, ['rivalplumbing.com'], null)], $asked), app(CompetitorTargets::class), app(CompetitorClassifier::class)))->run($site);
    LinkProspect::withoutGlobalScope(SiteScope::class)->where('domain', 'bucks-chamber.org')->first()
        ->forceFill(['status' => LinkProspectStatus::Contacted, 'notes' => 'emailed Jan'])->save();

    $second = (new LinkProspector(intersectProvider([new IntersectRow('bucks-chamber.org', 44, 0, ['rivalplumbing.com', 'dryguys.com'], null)], $asked), app(CompetitorTargets::class), app(CompetitorClassifier::class)))->run($site);

    $p = LinkProspect::withoutGlobalScope(SiteScope::class)->where('domain', 'bucks-chamber.org')->first();
    expect($first['new'])->toBe(1)->and($second['new'])->toBe(0)->and($second['refreshed'])->toBe(1)
        ->and($p->rank)->toBe(44)->and($p->competitor_count)->toBe(2)
        ->and($p->status)->toBe(LinkProspectStatus::Contacted)->and($p->notes)->toBe('emailed Jan');
});

it('does nothing and says so when Town Rank has found no local competitor yet', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sumppumpgurus.com', 'brand_name' => 'Sump Pump Gurus']);
    $asked = [];
    app()->instance(BacklinkProvider::class, intersectProvider([], $asked));

    $this->artisan('launchpad:find-link-prospects', ['--site' => 'Sump Pump Gurus'])
        ->expectsOutputToContain('no local competitors in Town Rank yet')
        ->assertSuccessful();

    expect($asked)->toBe([]);
});

it('the command reports the competitors asked about and what the list gained', function () {
    $site = prospectSite();
    $asked = [];
    app()->instance(BacklinkProvider::class, intersectProvider([new IntersectRow('bucks-chamber.org', 41, 0, ['rivalplumbing.com'], null)], $asked));

    $this->artisan('launchpad:find-link-prospects', ['--site' => $site->id])
        ->expectsOutputToContain('dryguys.com (organic, above us in 1 town results), rivalplumbing.com (organic, above us in 1 town results)')
        ->expectsOutputToContain('the index returned 1 linking domains; 1 kept (0 dropped as spam, 0 below the authority floor) — 1 new prospect(s), 0 refreshed, 0 untouched prospect(s) from an earlier run dropped.')
        ->assertSuccessful();
});

it('the command names the competitors before the index call, so a refused intersect still shows what was asked', function () {
    $site = prospectSite();
    app()->instance(BacklinkProvider::class, new class implements BacklinkProvider
    {
        public function summary(string $target): BacklinkSummary
        {
            return new BacklinkSummary(0, 0, 0, null);
        }

        public function referringDomains(string $target, int $limit = 1000, ?string $after = null): BacklinkPage
        {
            return new BacklinkPage([], 0, null);
        }

        public function domainIntersection(array $targets, array $exclude, int $limit = 300): array
        {
            throw new DataForSeoException('DataForSEO HTTP 500: Internal Error', 500);
        }
    });

    $this->artisan('launchpad:find-link-prospects', ['--site' => $site->id])
        ->expectsOutputToContain('competitors asked about: dryguys.com (organic, above us in 1 town results), rivalplumbing.com (organic, above us in 1 town results)')
        ->expectsOutputToContain('The backlink index refused the intersect: DataForSEO HTTP 500: Internal Error')
        ->assertFailed();

    expect(LinkProspect::withoutGlobalScope(SiteScope::class)->count())->toBe(0);
});

it('drops the untouched prospects an earlier run produced when the latest run no longer returns them, and keeps the worked ones', function () {
    $site = prospectSite();
    $asked = [];
    $mk = fn (array $rows): LinkProspector => new LinkProspector(intersectProvider($rows, $asked), app(CompetitorTargets::class), app(CompetitorClassifier::class));
    $mk([new IntersectRow('stale-forum.example', 50, 0, ['rivalplumbing.com'], null), new IntersectRow('worked.example', 50, 0, ['rivalplumbing.com'], null), new IntersectRow('bucks-chamber.org', 41, 0, ['rivalplumbing.com'], null)])->run($site);
    LinkProspect::withoutGlobalScope(SiteScope::class)->where('domain', 'worked.example')->first()->forceFill(['status' => LinkProspectStatus::Contacted])->save();
    $this->travel(1)->minutes();

    $r = $mk([new IntersectRow('bucks-chamber.org', 41, 0, ['rivalplumbing.com'], null)])->run($site);

    expect($r['pruned'])->toBe(1)
        ->and(LinkProspect::withoutGlobalScope(SiteScope::class)->pluck('domain')->sort()->values()->all())->toBe(['bucks-chamber.org', 'worked.example']);
});

it('keeps a run record — what was asked, what came back, what was kept — and a failed call is recorded with its reason', function () {
    $site = prospectSite();
    $asked = [];
    $mk = fn (BacklinkProvider $p): LinkProspector => new LinkProspector($p, app(CompetitorTargets::class), app(CompetitorClassifier::class));
    $mk(intersectProvider([new IntersectRow('bucks-chamber.org', 41, 0, ['rivalplumbing.com'], null), new IntersectRow('spamdir.example', 60, 45, ['dryguys.com'], null)], $asked))->run($site);

    $run = LinkProspectRun::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
    expect($run->status)->toBe('complete')
        ->and(array_column($run->targets, 'domain'))->toBe(['dryguys.com', 'rivalplumbing.com'])
        ->and($run->targets[0]['source'])->toBe('organic')
        ->and($run->returned)->toBe(2)->and($run->kept)->toBe(1)->and($run->dropped_spam)->toBe(1)->and($run->new)->toBe(1);

    $failing = new class implements BacklinkProvider
    {
        public function summary(string $target): BacklinkSummary
        {
            return new BacklinkSummary(0, 0, 0, null);
        }

        public function referringDomains(string $target, int $limit = 1000, ?string $after = null): BacklinkPage
        {
            return new BacklinkPage([], 0, null);
        }

        public function domainIntersection(array $targets, array $exclude, int $limit = 300): array
        {
            throw new DataForSeoException('DataForSEO HTTP 500: Internal Error', 500);
        }
    };
    expect(fn () => $mk($failing)->run($site))->toThrow(DataForSeoException::class);

    $failed = LinkProspectRun::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->orderByDesc('ran_at')->first();
    expect($failed->status)->toBe('failed')->and($failed->error)->toBe('DataForSEO HTTP 500: Internal Error')
        ->and(array_column($failed->targets, 'domain'))->toBe(['dryguys.com', 'rivalplumbing.com']);
});
