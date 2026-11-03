<?php

use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    $this->artisan('launchpad:mine-questions', ['site' => 'Acme Pumps', '--dropped' => true])
        ->expectsOutputToContain('queries read 2 · question-shaped 1')
        ->expectsOutputToContain('how often should a sump pump run')
        ->assertSuccessful();
});

it('fails on an unknown site and warns with no site', function () {
    $this->artisan('launchpad:mine-questions', ['site' => 'nobody'])->assertFailed();
    $this->artisan('launchpad:mine-questions')->assertSuccessful();
});
