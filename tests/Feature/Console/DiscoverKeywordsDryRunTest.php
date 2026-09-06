<?php

use App\Integrations\DataForSeo\KeywordIdea;
use App\Integrations\Keywords\KeywordIdeaProvider;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;

it('dry-run previews discovery per silo and writes nothing', function () {
    app()->instance(KeywordIdeaProvider::class, new class implements KeywordIdeaProvider
    {
        public function ideas(Site $site, string $seed, int $limit): array
        {
            return [new KeywordIdea("{$seed} repair", 400, null, 15)];
        }
    });

    $site = Site::factory()->create(['brand_name' => 'DryRun Co']);
    Silo::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'name' => 'Sump Pumps', 'type' => 'service_pillar',
        'rule_set' => ['include_patterns' => ['Sump Pumps'], 'seed_terms' => ['sump pump'], 'exclude_patterns' => []],
    ]);

    Artisan::call('launchpad:discover-keywords', ['--site' => $site->id, '--dry-run' => true]);
    $out = Artisan::output();

    expect($out)->toContain('DRY-RUN')
        ->and($out)->toContain('would generate 1 new keyword(s)')
        ->and($out)->toContain('sump pump repair')
        // The point: a dry-run must persist nothing.
        ->and(Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});

it('dry-run flags a silo with no seed terms as NO SEEDS', function () {
    app()->instance(KeywordIdeaProvider::class, new class implements KeywordIdeaProvider
    {
        public function ideas(Site $site, string $seed, int $limit): array
        {
            return [];
        }
    });

    $site = Site::factory()->create(['brand_name' => 'Starved Co']);
    Silo::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'name' => 'Bare Silo', 'type' => 'service_pillar',
        // no rule_set at all → no seeds to expand from
    ]);

    Artisan::call('launchpad:discover-keywords', ['--site' => $site->id, '--dry-run' => true]);

    expect(Artisan::output())->toContain('NO SEEDS');
});
