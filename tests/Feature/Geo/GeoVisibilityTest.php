<?php

use App\Enums\GeoCheckAction;
use App\Geo\GeoAnswerJudge;
use App\Geo\GeoCheckStatus;
use App\Geo\GeoVisibilityAudit;
use App\Integrations\AiSearch\AiAnswer;
use App\Integrations\AiSearch\AiEngineProvider;
use App\Integrations\AiSearch\AiEngineRegistry;
use App\Models\CoverageArea;
use App\Models\GeoCheckEvent;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Collection;
use Tests\Support\ScriptedClaudeClient;

/** @return Collection<int, GeoCheckEvent> */
function geoEvents(Site $site)
{
    return GeoCheckEvent::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
}

afterEach(fn () => CurrentSite::clear());

/** A deterministic AI engine keyed by prompt text — no HTTP. */
function fakeEngine(array $byPrompt, bool $enabled = true, string $key = 'claude'): AiEngineProvider
{
    return new class($byPrompt, $enabled, $key) implements AiEngineProvider
    {
        public function __construct(private array $byPrompt, private bool $enabled, private string $key) {}

        public function key(): string
        {
            return $this->key;
        }

        public function enabled(): bool
        {
            return $this->enabled;
        }

        public function ask(string $prompt): ?AiAnswer
        {
            return $this->byPrompt[$prompt] ?? null;
        }
    };
}

/** A registry pre-loaded with the given engines. */
function geoRegistry(AiEngineProvider ...$engines): AiEngineRegistry
{
    $registry = new AiEngineRegistry;
    foreach ($engines as $engine) {
        $registry->register($engine);
    }

    return $registry;
}

function judgeReturning(array $json): GeoAnswerJudge
{
    return new GeoAnswerJudge((new ScriptedClaudeClient)->fallback(json_encode($json)));
}

function geoSite(): Site
{
    return Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://sumppumpgurus.com']);
}

function geoPrompt(Site $site, string $prompt, bool $active = true): GeoPrompt
{
    return GeoPrompt::create(['site_id' => $site->id, 'prompt' => $prompt, 'active' => $active]);
}

it('forces cited on a domain match and nulls sentiment/position when absent', function () {
    $judge = judgeReturning(['cited' => false, 'position' => 2, 'sentiment' => 'positive', 'competitors' => ['Rival Plumbing']]);

    // Domain is cited even though the model said cited=false → cited wins; position + sentiment kept.
    $hit = $judge->judge('Sump Pump Gurus', 'https://sumppumpgurus.com', 'best sump pump repair',
        new AiAnswer('Several providers exist.', [['url' => 'https://sumppumpgurus.com/', 'title' => 'SPG']]));
    expect($hit->cited)->toBeTrue()->and($hit->position)->toBe(2)->and($hit->sentiment)->toBe('positive')
        ->and($hit->competitors)->toBe(['Rival Plumbing']);

    // No brand mention, no citation → not cited → position null, sentiment 'absent'.
    $miss = judgeReturning(['cited' => false, 'position' => 1, 'sentiment' => 'positive', 'competitors' => ['Acme']])
        ->judge('Sump Pump Gurus', 'https://sumppumpgurus.com', 'best sump pump repair',
            new AiAnswer('Try Acme Plumbing.', []));
    expect($miss->cited)->toBeFalse()->and($miss->position)->toBeNull()->and($miss->sentiment)->toBe('absent');
});

it('audits active prompts and writes durable snapshots (inactive excluded)', function () {
    $site = geoSite();
    $p1 = geoPrompt($site, 'best sump pump repair union nj');
    $p2 = geoPrompt($site, 'how to fix a sump pump');
    geoPrompt($site, 'inactive prompt', active: false);

    $engine = fakeEngine([
        'best sump pump repair union nj' => new AiAnswer('Sump Pump Gurus is top rated.', [['url' => 'https://sumppumpgurus.com/', 'title' => 'SPG']]),
        'how to fix a sump pump' => new AiAnswer('A general guide with no brands.', []),
    ]);
    $r = (new GeoVisibilityAudit(geoRegistry($engine), judgeReturning(['cited' => false, 'position' => 1, 'sentiment' => 'positive', 'competitors' => []])))
        ->audit($site);

    expect($r)->toMatchArray(['enabled' => true, 'total' => 2, 'measured' => 2, 'skipped_fresh' => 0, 'deferred' => 0]);

    $s1 = GeoSnapshot::withoutGlobalScope(SiteScope::class)->where('geo_prompt_id', $p1->id)->first();
    expect($s1->cited)->toBeTrue()->and($s1->position)->toBe(1)->and($s1->sentiment)->toBe('positive')->and($s1->engine)->toBe('claude');
    expect(GeoSnapshot::withoutGlobalScope(SiteScope::class)->where('geo_prompt_id', $p2->id)->first()->cited)->toBeFalse();
});

it('fans a prompt out across every enabled engine, one snapshot per engine', function () {
    $site = geoSite();
    $prompt = geoPrompt($site, 'best sump pump repair');

    $claude = fakeEngine(['best sump pump repair' => new AiAnswer('Sump Pump Gurus is great.', [['url' => 'https://sumppumpgurus.com/', 'title' => 'SPG']])], key: 'claude');
    $perplexity = fakeEngine(['best sump pump repair' => new AiAnswer('Consider Acme Plumbing.', [])], key: 'perplexity');

    $r = (new GeoVisibilityAudit(geoRegistry($claude, $perplexity), judgeReturning(['cited' => false, 'position' => 1, 'sentiment' => 'positive', 'competitors' => []])))
        ->audit($site);

    expect($r)->toMatchArray(['enabled' => true, 'engines' => 2, 'total' => 2, 'measured' => 2]);

    $byEngine = GeoSnapshot::withoutGlobalScope(SiteScope::class)->where('geo_prompt_id', $prompt->id)->get()->keyBy('engine');
    expect($byEngine)->toHaveCount(2)
        ->and($byEngine['claude']->cited)->toBeTrue()     // domain cited
        ->and($byEngine['perplexity']->cited)->toBeFalse(); // brand absent
});

it('skips a prompt whose latest snapshot is still fresh', function () {
    $site = geoSite();
    $p = geoPrompt($site, 'a prompt');
    GeoSnapshot::create(['site_id' => $site->id, 'geo_prompt_id' => $p->id, 'engine' => 'claude',
        'cited' => true, 'sentiment' => 'positive', 'checked_at' => now()->subDay()]);

    $r = (new GeoVisibilityAudit(geoRegistry(fakeEngine([])), judgeReturning([])))->audit($site, freshnessDays: 6);

    expect($r['skipped_fresh'])->toBe(1)->and($r['measured'])->toBe(0);
});

it('defers prompts once the budget is spent', function () {
    $site = geoSite();
    geoPrompt($site, 'p1');
    geoPrompt($site, 'p2');

    $r = (new GeoVisibilityAudit(geoRegistry(fakeEngine([])), judgeReturning([])))->audit($site, budgetSeconds: 0.0);

    expect($r['measured'])->toBe(0)->and($r['deferred'])->toBe(2)
        ->and(GeoSnapshot::withoutGlobalScope(SiteScope::class)->count())->toBe(0);
});

it('is a clean no-op when the engine is disabled', function () {
    $site = geoSite();
    geoPrompt($site, 'p1');

    expect((new GeoVisibilityAudit(geoRegistry(fakeEngine([], enabled: false)), judgeReturning([])))->audit($site)['enabled'])->toBeFalse()
        ->and(GeoSnapshot::withoutGlobalScope(SiteScope::class)->count())->toBe(0);
});

it('the sync-geo command runs for a site', function () {
    $site = geoSite();
    $p = geoPrompt($site, 'best sump pump repair');
    $engine = fakeEngine(['best sump pump repair' => new AiAnswer('Sump Pump Gurus leads.', [])]);
    app()->instance(GeoVisibilityAudit::class, new GeoVisibilityAudit(geoRegistry($engine), judgeReturning(['cited' => true, 'position' => 1, 'sentiment' => 'positive', 'competitors' => []])));

    $this->artisan('sandhog:sync-geo', ['site' => $site->id])
        ->expectsOutputToContain('1 measured')
        ->assertSuccessful();
});

it('writes a measured activity-log event (with town, cited, competitors) per measured pair', function () {
    $site = geoSite();
    $town = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Union', 'state' => 'NJ', 'size_tier' => 'major', 'population' => 60000, 'page_selected' => true]);
    GeoPrompt::create(['site_id' => $site->id, 'coverage_area_id' => $town->id, 'size_tier' => 'major', 'prompt' => 'best repair union', 'active' => true]);

    $engine = fakeEngine(['best repair union' => new AiAnswer('Acme leads.', [])]);
    (new GeoVisibilityAudit(geoRegistry($engine), judgeReturning(['cited' => false, 'position' => 1, 'sentiment' => 'positive', 'competitors' => ['Acme']])))->audit($site);

    $event = geoEvents($site)->sole();
    expect($event->action)->toBe(GeoCheckAction::Measured)
        ->and($event->town)->toBe('Union')
        ->and($event->engine)->toBe('claude')
        ->and($event->cited)->toBeFalse()
        ->and($event->competitors)->toBe(['Acme'])
        ->and($event->run_id)->not->toBeNull();
});

it('logs skipped-fresh, deferred, and error steps', function () {
    // Fresh → skipped.
    $freshSite = geoSite();
    $p = geoPrompt($freshSite, 'a prompt');
    GeoSnapshot::create(['site_id' => $freshSite->id, 'geo_prompt_id' => $p->id, 'engine' => 'claude', 'cited' => true, 'checked_at' => now()->subDay()]);
    (new GeoVisibilityAudit(geoRegistry(fakeEngine([])), judgeReturning([])))->audit($freshSite, freshnessDays: 6);
    expect(geoEvents($freshSite)->pluck('action')->all())->toBe([GeoCheckAction::SkippedFresh]);

    // Budget spent → deferred.
    $budgetSite = geoSite();
    geoPrompt($budgetSite, 'p1');
    (new GeoVisibilityAudit(geoRegistry(fakeEngine([])), judgeReturning([])))->audit($budgetSite, budgetSeconds: 0.0);
    expect(geoEvents($budgetSite)->pluck('action')->all())->toBe([GeoCheckAction::Deferred]);

    // Engine returns null → error.
    $errSite = geoSite();
    geoPrompt($errSite, 'unanswered');
    (new GeoVisibilityAudit(geoRegistry(fakeEngine([])), judgeReturning([])))->audit($errSite);
    expect(geoEvents($errSite)->pluck('action')->all())->toBe([GeoCheckAction::Error]);
});

it('marks the tenant checking during the run and clears the flag when done', function () {
    $site = geoSite();
    geoPrompt($site, 'q1');

    $seenRunning = false;
    $engine = new class implements AiEngineProvider
    {
        public Closure $onAsk;

        public function key(): string
        {
            return 'claude';
        }

        public function enabled(): bool
        {
            return true;
        }

        public function ask(string $prompt): ?AiAnswer
        {
            ($this->onAsk)();

            return new AiAnswer('Sump Pump Gurus is great.', []);
        }
    };
    $engine->onAsk = function () use (&$seenRunning, $site): void {
        $seenRunning = app(GeoCheckStatus::class)->isRunning($site->id);
    };

    (new GeoVisibilityAudit(geoRegistry($engine), judgeReturning(['cited' => true, 'position' => 1, 'sentiment' => 'positive', 'competitors' => []])))
        ->audit($site);

    expect($seenRunning)->toBeTrue()                                        // flag was set while measuring
        ->and(app(GeoCheckStatus::class)->isRunning($site->id))->toBeFalse(); // and cleared afterwards
});
