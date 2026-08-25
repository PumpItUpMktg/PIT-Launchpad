<?php

use App\Enums\GeoIntent;
use App\Enums\GeoPromptSource;
use App\Geo\GeoPromptTopUp;
use App\Models\CoverageArea;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Collection;
use Tests\Support\ScriptedClaudeClient;

afterEach(fn () => CurrentSite::clear());

function topUpWith(array $variants): GeoPromptTopUp
{
    return new GeoPromptTopUp((new ScriptedClaudeClient)->fallback(json_encode(['variants' => $variants])));
}

function absentPrompt(Site $site, Service $svc, CoverageArea $town, array $competitors = ['Rival']): GeoPrompt
{
    $p = GeoPrompt::create([
        'site_id' => $site->id, 'service_id' => $svc->id, 'coverage_area_id' => $town->id, 'size_tier' => $town->size_tier,
        'intent' => GeoIntent::Hire->value, 'prompt' => 'original question', 'active' => true, 'label' => 'Repair · Hire',
    ]);
    GeoSnapshot::create(['site_id' => $site->id, 'geo_prompt_id' => $p->id, 'engine' => 'claude', 'cited' => false, 'competitors' => $competitors, 'checked_at' => now()]);

    return $p;
}

function tuSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);

    return [
        $site,
        Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']),
        CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Union', 'state' => 'NJ', 'size_tier' => 'major', 'population' => 60000, 'page_selected' => true]),
    ];
}

/** @return Collection<int, GeoPrompt> */
function assistedPrompts(Site $site)
{
    return GeoPrompt::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('source', GeoPromptSource::Assisted->value)->get();
}

it('generates assisted variants for an absent gap, tagged with the parent dimensions', function () {
    [$site, $svc, $town] = tuSite();
    absentPrompt($site, $svc, $town);

    $r = topUpWith([
        'who repairs sump pumps in union nj',
        'sump pump repair near me in union',
        'is Sump Pump Gurus the best?',   // names the brand → dropped (must stay neutral)
    ])->topUp($site);

    expect($r)->toMatchArray(['gaps_addressed' => 1, 'created' => 2]);   // brand-named variant filtered out

    $variants = assistedPrompts($site);
    expect($variants)->toHaveCount(2)
        ->and($variants->first()->service_id)->toBe($svc->id)
        ->and($variants->first()->coverage_area_id)->toBe($town->id)
        ->and($variants->first()->size_tier?->value)->toBe('major')
        ->and($variants->first()->intent)->toBe(GeoIntent::Hire)
        ->and($variants->contains(fn (GeoPrompt $v): bool => str_contains(mb_strtolower($v->prompt), 'sump pump gurus')))->toBeFalse();
});

it('ignores prompts that are already cited (not a gap)', function () {
    [$site, $svc, $town] = tuSite();
    $cited = GeoPrompt::create(['site_id' => $site->id, 'service_id' => $svc->id, 'coverage_area_id' => $town->id, 'intent' => GeoIntent::Hire->value, 'prompt' => 'q', 'active' => true]);
    GeoSnapshot::create(['site_id' => $site->id, 'geo_prompt_id' => $cited->id, 'engine' => 'claude', 'cited' => true, 'checked_at' => now()]);

    expect(topUpWith(['a', 'b'])->topUp($site))->toMatchArray(['gaps_addressed' => 0, 'created' => 0]);
});

it('does not duplicate an existing prompt text', function () {
    [$site, $svc, $town] = tuSite();
    absentPrompt($site, $svc, $town);
    // A manual prompt already exists with a text the generator will also return.
    GeoPrompt::create(['site_id' => $site->id, 'prompt' => 'Existing variant', 'active' => true]);

    $r = topUpWith(['Existing variant', 'A fresh variant'])->topUp($site);

    expect($r['created'])->toBe(1)   // the duplicate is skipped
        ->and(assistedPrompts($site)->pluck('prompt')->all())->toBe(['A fresh variant']);
});

it('bounds variants per gap', function () {
    config(['launchpad.geo.topup.max_variants_per_gap' => 1]);
    [$site, $svc, $town] = tuSite();
    absentPrompt($site, $svc, $town);

    expect(topUpWith(['one', 'two', 'three'])->topUp($site)['created'])->toBe(1);
});

it('the topup-geo-prompts command runs for a site', function () {
    [$site, $svc, $town] = tuSite();
    absentPrompt($site, $svc, $town);
    app()->instance(GeoPromptTopUp::class, topUpWith(['who fixes sump pumps in union']));

    $this->artisan('sandhog:topup-geo-prompts', ['site' => $site->id])
        ->expectsOutputToContain('created')
        ->assertSuccessful();
});
