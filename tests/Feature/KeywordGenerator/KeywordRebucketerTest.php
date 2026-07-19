<?php

use App\KeywordGenerator\KeywordRebucketer;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;

function krSilo(Site $site, string $name, array $include): Silo
{
    return Silo::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'name' => $name, 'type' => 'service_pillar',
        'rule_set' => ['include_patterns' => $include, 'seed_terms' => [], 'exclude_patterns' => []],
    ]);
}

function krKeyword(Site $site, string $query, ?string $siloId = null): Keyword
{
    return Keyword::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'silo_id' => $siloId, 'query' => $query, 'source' => 'seed', 'status' => 'candidate',
    ]);
}

it('re-files unassigned keywords into the matching silo, leaves no-match ones alone', function () {
    $site = Site::factory()->create();
    $sump = krSilo($site, 'Sump Pumps', ['sump pump']);
    $matches = krKeyword($site, 'sump pump installation');   // matches Sump Pumps
    $orphan = krKeyword($site, 'garage door spring repair');  // matches nothing → stays unassigned
    $already = krKeyword($site, 'sump pump repair', $sump->id); // already assigned → untouched

    $count = app(KeywordRebucketer::class)->rebucket($site);

    expect($count)->toBe(1)
        ->and($matches->fresh()->silo_id)->toBe($sump->id)
        ->and($orphan->fresh()->silo_id)->toBeNull()
        ->and($already->fresh()->silo_id)->toBe($sump->id);
});

it('does nothing when the silos have no rule_sets (nothing to match on)', function () {
    $site = Site::factory()->create();
    Silo::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'name' => 'Sump Pumps', 'type' => 'service_pillar']); // no rule_set
    krKeyword($site, 'sump pump installation');

    expect(app(KeywordRebucketer::class)->rebucket($site))->toBe(0);
});

it('the command re-files unassigned keywords for a site', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $sump = krSilo($site, 'Sump Pumps', ['sump pump']);
    $kw = krKeyword($site, 'sump pump battery backup');

    $this->artisan('launchpad:rebucket-keywords', ['--site' => $site->id])
        ->expectsOutputToContain('re-filed 1')
        ->assertSuccessful();

    expect($kw->fresh()->silo_id)->toBe($sump->id);
});
