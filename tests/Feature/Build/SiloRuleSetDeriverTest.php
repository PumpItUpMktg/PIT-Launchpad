<?php

use App\Build\GuidedEntityProjector;
use App\Build\SiloRuleSetDeriver;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\KeywordGenerator\Bucketer;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

function rsSilo(Site $site, string $name): Silo
{
    return Silo::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'name' => $name, 'type' => 'service_pillar']);
}

/** @param array<string, mixed> $attrs */
function rsSpoke(Site $site, SiloBlueprint $bp, string $silo, string $name, string $head, bool $pillar = false, array $attrs = []): Spoke
{
    return Spoke::factory()->create(array_merge([
        'site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => $silo,
        'name' => $name, 'head_keyword' => $head, 'is_pillar' => $pillar, 'tag' => SpokeTag::Core,
    ], $attrs));
}

it('derives a rule_set for a guided silo from its spokes (pillar head + name broad, spoke heads seeded)', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    $silo = rsSilo($site, 'Sump Pumps');
    rsSpoke($site, $bp, 'Sump Pumps', 'Sump Pumps', 'sump pump', pillar: true);
    rsSpoke($site, $bp, 'Sump Pumps', 'Sump Pump Installation', 'sump pump installation');
    rsSpoke($site, $bp, 'Sump Pumps', 'Sump Pump Repair', 'sump pump repair');

    $updated = app(SiloRuleSetDeriver::class)->deriveForSite($site);

    expect($updated)->toBe(1);
    $rs = $silo->fresh()->rule_set;
    expect($rs['include_patterns'])->toContain('sump pump')->toContain('sump pumps') // pillar head + silo name
        ->and($rs['seed_terms'])->toContain('sump pump installation')->toContain('sump pump repair');
});

it('lets the Bucketer route a discovered keyword into the silo (the whole point)', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    $sump = rsSilo($site, 'Sump Pumps');
    $crawl = rsSilo($site, 'Crawl Space Solutions');
    rsSpoke($site, $bp, 'Sump Pumps', 'Sump Pumps', 'sump pump', pillar: true);
    rsSpoke($site, $bp, 'Crawl Space Solutions', 'Crawl Space Solutions', 'crawl space', pillar: true);

    app(SiloRuleSetDeriver::class)->deriveForSite($site);

    $silos = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
    $bucketer = app(Bucketer::class);

    // A keyword discovery would surface — with no rule_set it bucketed nowhere (thin); now it routes.
    expect($bucketer->bucket('sump pump battery backup', $silos)?->id)->toBe($sump->id)
        ->and($bucketer->bucket('crawl space vapor barrier', $silos)?->id)->toBe($crawl->id);
});

it('never overwrites a silo that already carries a rule_set', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    $silo = rsSilo($site, 'Sump Pumps');
    $silo->forceFill(['rule_set' => ['include_patterns' => ['committed'], 'seed_terms' => [], 'exclude_patterns' => []]])->save();
    rsSpoke($site, $bp, 'Sump Pumps', 'Sump Pumps', 'sump pump', pillar: true);

    $updated = app(SiloRuleSetDeriver::class)->deriveForSite($site);

    expect($updated)->toBe(0)
        ->and($silo->fresh()->rule_set['include_patterns'])->toBe(['committed']); // untouched
});

it('the projector gives freshly-projected silos rule_sets at materialize', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    Spoke::factory()->create([
        'site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Pumps',
        'name' => 'Pumps', 'head_keyword' => 'pump', 'is_pillar' => true, 'tag' => SpokeTag::Core,
        'status' => SpokeStatus::Offered,
    ]);

    app(GuidedEntityProjector::class)->project($site);

    $silo = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', 'Pumps')->first();
    expect($silo)->not->toBeNull()
        ->and($silo->rule_set['include_patterns'] ?? [])->toContain('pump');
});

it('the command dry-runs by default and writes only with --force', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    $silo = rsSilo($site, 'Sump Pumps');
    rsSpoke($site, $bp, 'Sump Pumps', 'Sump Pumps', 'sump pump', pillar: true);

    $this->artisan('launchpad:derive-silo-rulesets', ['--site' => $site->id])
        ->expectsOutputToContain('would give rule_sets to 1 silo')
        ->expectsOutputToContain('[dry-run]')
        ->assertSuccessful();
    expect($silo->fresh()->rule_set)->toBeNull();

    $this->artisan('launchpad:derive-silo-rulesets', ['--site' => $site->id, '--force' => true])
        ->expectsOutputToContain('gave rule_sets to 1 silo')
        ->assertSuccessful();
    expect($silo->fresh()->rule_set)->not->toBeNull();
});
