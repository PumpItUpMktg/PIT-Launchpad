<?php

use App\Integrations\DataForSeo\KeywordIdea;
use App\Integrations\Keywords\KeywordIdeaProvider;
use App\KeywordGenerator\Discovery\SiloKeywordGenerator;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;

/** A deterministic idea source: each seed → the seed itself + a couple of variants + one geo term. */
class FakeIdeaProvider implements KeywordIdeaProvider
{
    public function ideas(Site $site, string $seed, int $limit): array
    {
        return array_slice([
            new KeywordIdea("{$seed} cost", 500, null, 20),
            new KeywordIdea("{$seed} installation", 300, null, 25),
            new KeywordIdea("{$seed} near me", 800, null, 10), // geo — must be dropped
        ], 0, $limit);
    }
}

function skgSilo(Site $site, string $name, array $seeds): Silo
{
    return Silo::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'name' => $name, 'type' => 'service_pillar',
        'rule_set' => ['include_patterns' => [$name], 'seed_terms' => $seeds, 'exclude_patterns' => []],
    ]);
}

beforeEach(function () {
    app()->instance(KeywordIdeaProvider::class, new FakeIdeaProvider);
});

it('generates deduped, silo-pinned keyword candidates from each silo\'s seeds, dropping geo terms', function () {
    $site = Site::factory()->create();
    $silo = skgSilo($site, 'Sump Pumps', ['sump pump', 'sump pit']);

    $created = app(SiloKeywordGenerator::class)->generate($site);

    $rows = Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();

    // 2 seeds × 2 non-geo ideas = 4 new rows, all pinned to the silo, none containing "near me".
    expect($created)->toBe(4)
        ->and($rows)->toHaveCount(4)
        ->and($rows->every(fn (Keyword $k) => $k->silo_id === $silo->id))->toBeTrue()
        ->and($rows->every(fn (Keyword $k) => ! str_contains((string) $k->query, 'near me')))->toBeTrue()
        ->and($rows->pluck('query'))->toContain('sump pump cost', 'sump pit installation');
});

it('does not duplicate a keyword the site already has', function () {
    $site = Site::factory()->create();
    $silo = skgSilo($site, 'Sump Pumps', ['sump pump']);
    Keyword::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'sump pump cost', 'source' => 'seed', 'status' => 'candidate',
    ]);

    $created = app(SiloKeywordGenerator::class)->generate($site);

    // "sump pump cost" already exists → only "sump pump installation" is new.
    expect($created)->toBe(1)
        ->and(Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('query', 'sump pump cost')->count())->toBe(1);
});

it('is a no-op for a silo with no rule_set terms to seed from', function () {
    $site = Site::factory()->create();
    Silo::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'name' => 'Bare', 'type' => 'service_pillar']);

    expect(app(SiloKeywordGenerator::class)->generate($site))->toBe(0);
});
