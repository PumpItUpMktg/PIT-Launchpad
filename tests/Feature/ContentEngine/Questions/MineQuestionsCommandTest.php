<?php

use App\Integrations\Embedding\EmbeddingProvider;
use App\Integrations\Embedding\MockEmbeddingProvider;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->app->instance(EmbeddingProvider::class, new MockEmbeddingProvider);
});

it('prints the report for a site and lists dropped queries on request', function () {
    $site = Site::factory()->create(['brand_name' => 'Acme Pumps', 'gsc_property' => 'sc-domain:acmepumps.test']);
    $insert = function (string $q, int $imp, int $clk, float $pos, string $date) use ($site): void {
        DB::table('gsc_url_query_daily')->insert([
            'id' => (string) Str::ulid(), 'site_id' => $site->id,
            'grain_hash' => hash('sha256', $site->id.$date.$q), 'date' => $date, 'url' => 'https://acmepumps.test/a/',
            'query' => $q, 'country' => 'usa', 'device' => 'MOBILE', 'impressions' => $imp, 'clicks' => $clk,
            'ctr' => $imp > 0 ? $clk / $imp : 0, 'position' => $pos, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $insert('how often should a sump pump run', 90, 4, 7.0, now()->subDays(20)->toDateString());
    $insert('sump pump design ideas 2025', 900, 0, 2.0, now()->subDays(2)->toDateString());

    expect(Artisan::call('launchpad:mine-questions', ['site' => 'Acme Pumps', '--dropped' => true]))->toBe(0);
    $out = Artisan::output();

    expect($out)->toContain('queries read 2 · question-shaped 1')
        ->toContain('how often should a sump pump run')
        ->toContain('unrouted (no silo claims it)')
        ->toContain('Report only')
        ->and(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});

it('files the candidates with --execute', function () {
    $site = Site::factory()->create(['brand_name' => 'Acme Pumps', 'domain_url' => 'https://acmepumps.test', 'gsc_property' => 'sc-domain:acmepumps.test']);
    Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps', 'rule_set' => ['include_patterns' => ['sump pump'], 'seed_terms' => [], 'exclude_patterns' => []]]);
    DB::table('gsc_url_query_daily')->insert([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'grain_hash' => hash('sha256', $site->id.'x'),
        'date' => now()->subDays(20)->toDateString(), 'url' => 'https://acmepumps.test/', 'query' => 'how often should a sump pump run',
        'country' => 'usa', 'device' => 'MOBILE', 'impressions' => 90, 'clicks' => 4, 'ctr' => 0.044, 'position' => 7.0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(Artisan::call('launchpad:mine-questions', ['--all' => true, '--execute' => true]))->toBe(0);
    $out = Artisan::output();
    expect($out)->toContain('file [Sump Pumps]')->toContain('filed 1 candidate(s) · marked 0 question(s) on 0 post(s)');

    $c = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->sole();
    expect($c->title)->toBe('How often should a sump pump run?')->and($c->source_name)->toBe('questions');
});

it('fails on an unknown site and warns with no site', function () {
    $this->artisan('launchpad:mine-questions', ['site' => 'nobody'])->assertFailed();
    $this->artisan('launchpad:mine-questions')->assertSuccessful();
});
