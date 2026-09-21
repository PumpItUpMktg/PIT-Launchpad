<?php

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Str;

afterEach(function () {
    CurrentSite::clear();
});

function legacyHit(Site $site, string $path, int $impressions): void
{
    GscUrlDaily::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'grain_hash' => Str::random(32),
        'date' => now()->subDays(3)->toDateString(),
        'url' => rtrim((string) $site->domain_url, '/').$path,
        'impressions' => $impressions,
        'clicks' => 3,
        'position' => 12.0,
    ]);
}

it('names a limit of zero instead of blaming the impression floor', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    legacyHit($site, '/how-often-should-sump-pump-cycle', 78107);
    config(['launchpad.legacy_revival.limit' => 0]);

    // A 78,107-impression family is nowhere near the 5,000 floor — saying "nothing above the floor"
    // would send someone lowering a threshold that was never the problem.
    $this->artisan('launchpad:revive-legacy-content', ['--site' => $site->id])
        ->expectsOutputToContain('per-run limit is 0')
        ->assertSuccessful();
});

it('reports the pool it started from', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    legacyHit($site, '/tiny-legacy-article', 40);   // real, but far under the floor

    $this->artisan('launchpad:revive-legacy-content', ['--site' => $site->id])
        ->expectsOutputToContain('Pool:')
        ->expectsOutputToContain('fell below the 5,000-impression floor')
        ->assertSuccessful();
});

it('says so when everything is already claimed', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    legacyHit($site, '/how-often-should-sump-pump-cycle', 78107);

    Content::factory()->create([
        'site_id' => $site->id,
        'status' => ContentStatus::Candidate,
        'meta' => ['revived_from_urls' => ['/how-often-should-sump-pump-cycle']],
    ]);

    $this->artisan('launchpad:revive-legacy-content', ['--site' => $site->id])
        ->expectsOutputToContain('already claimed by a revival')
        ->assertSuccessful();
});

it('still plans normally when a family qualifies', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    legacyHit($site, '/how-often-should-sump-pump-cycle', 78107);

    $this->artisan('launchpad:revive-legacy-content', ['--site' => $site->id])
        ->expectsOutputToContain('1 family(ies) to revive')
        ->assertSuccessful();
});
