<?php

use App\Geo\GeoAnswerJudge;
use App\Geo\GeoVisibilityAudit;
use App\Integrations\AiSearch\AiAnswer;
use App\Integrations\AiSearch\AiEngineProvider;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\CurrentSite;
use Tests\Support\ScriptedClaudeClient;

afterEach(fn () => CurrentSite::clear());

/** A deterministic AI engine keyed by prompt text — no HTTP. */
function fakeEngine(array $byPrompt, bool $enabled = true): AiEngineProvider
{
    return new class($byPrompt, $enabled) implements AiEngineProvider
    {
        public function __construct(private array $byPrompt, private bool $enabled) {}

        public function key(): string
        {
            return 'claude';
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
    $r = (new GeoVisibilityAudit($engine, judgeReturning(['cited' => false, 'position' => 1, 'sentiment' => 'positive', 'competitors' => []])))
        ->audit($site);

    expect($r)->toMatchArray(['enabled' => true, 'total' => 2, 'measured' => 2, 'skipped_fresh' => 0, 'deferred' => 0]);

    $s1 = GeoSnapshot::withoutGlobalScope(SiteScope::class)->where('geo_prompt_id', $p1->id)->first();
    expect($s1->cited)->toBeTrue()->and($s1->position)->toBe(1)->and($s1->sentiment)->toBe('positive')->and($s1->engine)->toBe('claude');
    expect(GeoSnapshot::withoutGlobalScope(SiteScope::class)->where('geo_prompt_id', $p2->id)->first()->cited)->toBeFalse();
});

it('skips a prompt whose latest snapshot is still fresh', function () {
    $site = geoSite();
    $p = geoPrompt($site, 'a prompt');
    GeoSnapshot::create(['site_id' => $site->id, 'geo_prompt_id' => $p->id, 'engine' => 'claude',
        'cited' => true, 'sentiment' => 'positive', 'checked_at' => now()->subDay()]);

    $r = (new GeoVisibilityAudit(fakeEngine([]), judgeReturning([])))->audit($site, freshnessDays: 6);

    expect($r['skipped_fresh'])->toBe(1)->and($r['measured'])->toBe(0);
});

it('defers prompts once the budget is spent', function () {
    $site = geoSite();
    geoPrompt($site, 'p1');
    geoPrompt($site, 'p2');

    $r = (new GeoVisibilityAudit(fakeEngine([]), judgeReturning([])))->audit($site, budgetSeconds: 0.0);

    expect($r['measured'])->toBe(0)->and($r['deferred'])->toBe(2)
        ->and(GeoSnapshot::withoutGlobalScope(SiteScope::class)->count())->toBe(0);
});

it('is a clean no-op when the engine is disabled', function () {
    $site = geoSite();
    geoPrompt($site, 'p1');

    expect((new GeoVisibilityAudit(fakeEngine([], enabled: false), judgeReturning([])))->audit($site)['enabled'])->toBeFalse()
        ->and(GeoSnapshot::withoutGlobalScope(SiteScope::class)->count())->toBe(0);
});

it('the sync-geo command runs for a site', function () {
    $site = geoSite();
    $p = geoPrompt($site, 'best sump pump repair');
    $engine = fakeEngine(['best sump pump repair' => new AiAnswer('Sump Pump Gurus leads.', [])]);
    app()->instance(GeoVisibilityAudit::class, new GeoVisibilityAudit($engine, judgeReturning(['cited' => true, 'position' => 1, 'sentiment' => 'positive', 'competitors' => []])));

    $this->artisan('sandhog:sync-geo', ['site' => $site->id])
        ->expectsOutputToContain('1 measured')
        ->assertSuccessful();
});
