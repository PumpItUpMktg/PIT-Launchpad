<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\GscUrlQueryDaily;
use App\Models\Site;
use App\Publishing\Redirects\RevivalBrief;
use App\Support\CurrentSite;
use Illuminate\Support\Str;

afterEach(function () {
    CurrentSite::clear();
});

function briefQuery(Site $site, string $path, string $query, int $impressions): void
{
    GscUrlQueryDaily::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'grain_hash' => Str::random(32),
        'date' => now()->subDays(5)->toDateString(),
        'url' => rtrim((string) $site->domain_url, '/').$path,
        'query' => $query,
        'country' => 'usa',
        'device' => 'desktop',
        'impressions' => $impressions,
        'clicks' => 1,
        'position' => 9.0,
    ]);
}

it('sums a query across the whole family, not one URL at a time', function () {
    $site = Site::factory()->create();

    // Spread across three copies, "sump pump installation cost" is the term the cluster owns — even
    // though no single URL earns more on it than the one-URL spike below.
    briefQuery($site, '/cost-breakdown', 'sump pump installation cost', 20000);
    briefQuery($site, '/cost-breakdown-3', 'sump pump installation cost', 18000);
    briefQuery($site, '/cost-breakdown-4', 'sump pump installation cost', 15000);
    briefQuery($site, '/cost-breakdown-3', 'sump pump renovation cost', 30000);

    $queries = app(RevivalBrief::class)->for($site, ['/cost-breakdown', '/cost-breakdown-3', '/cost-breakdown-4']);

    expect($queries[0]['query'])->toBe('sump pump installation cost')
        ->and($queries[0]['impressions'])->toBe(53000)
        ->and($queries[1]['query'])->toBe('sump pump renovation cost');
});

it('drops the tail a drafter cannot act on', function () {
    $site = Site::factory()->create();
    briefQuery($site, '/a', 'sump pump alarm going off', 78000);
    briefQuery($site, '/a', 'sump pump beeping', 9000);
    briefQuery($site, '/a', 'why is my sump pump', 4);   // under 2% of the best

    expect(app(RevivalBrief::class)->for($site, ['/a']))->toHaveCount(2);
});

it('says the replacement must cover all of them', function () {
    $site = Site::factory()->create();
    briefQuery($site, '/a', 'sump pump check valve', 40000);
    briefQuery($site, '/a', 'check valve installation', 12000);

    $brief = app(RevivalBrief::class);
    $sentence = $brief->sentence($brief->for($site, ['/a']));

    expect($sentence)->toContain('not only the first')
        ->toContain('sump pump check valve')
        ->toContain('check valve installation');
});

it('is quiet when the family has no query data', function () {
    $site = Site::factory()->create();

    expect(app(RevivalBrief::class)->for($site, ['/never-tracked']))->toBe([])
        ->and(app(RevivalBrief::class)->sentence([]))->toBe('');
});

it('re-briefs an undrafted candidate and leaves a drafted one alone', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    briefQuery($site, '/cost-breakdown-3', 'sump pump renovation cost', 30000);
    briefQuery($site, '/cost-breakdown-3', 'sump pump installation cost', 25000);

    $undrafted = Content::factory()->create([
        'site_id' => $site->id,
        'status' => ContentStatus::Candidate,
        'kind' => ContentKind::Post,
        'title' => 'Sump Pump Renovation Cost',
        'body' => null,
        'angle_hint' => 'Revive a top-performing legacy article.',
        'meta' => ['revived_from_urls' => ['/cost-breakdown-3'], 'revived_query' => 'sump pump renovation cost'],
    ]);

    $this->artisan('launchpad:rebrief-revivals', ['--site' => $site->id, '--apply' => true])
        ->expectsOutputToContain('2 queries across 1 URL(s)')
        ->assertSuccessful();

    $fresh = $undrafted->fresh();
    expect($fresh->meta['revived_queries'])->toHaveCount(2)
        ->and($fresh->angle_hint)->toContain('not only the first');
});

it('is read-only without --apply', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    briefQuery($site, '/a', 'one', 5000);
    briefQuery($site, '/a', 'two', 4000);

    $candidate = Content::factory()->create([
        'site_id' => $site->id,
        'status' => ContentStatus::Candidate,
        'kind' => ContentKind::Post,
        'body' => null,
        'angle_hint' => 'original',
        'meta' => ['revived_from_urls' => ['/a'], 'revived_query' => 'one'],
    ]);

    $this->artisan('launchpad:rebrief-revivals', ['--site' => $site->id])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect($candidate->fresh()->angle_hint)->toBe('original');
});

it('never puts a site: operator into a brief', function () {
    $site = Site::factory()->create();
    briefQuery($site, '/winterize-sump-pump', 'winterize sump pump', 30000);
    briefQuery($site, '/winterize-sump-pump', 'site:sumppumpgurus.com', 12000);
    briefQuery($site, '/winterize-sump-pump', 'sump pump winter', 9000);

    // Real impressions, real data — and "write a post covering site:sumppumpgurus.com" is not an
    // instruction anyone can follow.
    $queries = app(RevivalBrief::class)->for($site, ['/winterize-sump-pump']);

    expect(collect($queries)->pluck('query')->all())->toBe(['winterize sump pump', 'sump pump winter']);
});

it('does not starve the biggest family of its tail', function () {
    $site = Site::factory()->create();
    // One dominant term plus a long tail — the shape of an 11-URL cluster. A share-only floor would cut
    // everything under 2% of 200,000, taking the tail that made the family big in the first place.
    briefQuery($site, '/cost-breakdown', 'sump pump installation cost', 200000);
    foreach (['sump pump replacement cost', 'sump pump cost', 'cost to install sump pump', 'sump pump price'] as $i => $query) {
        briefQuery($site, '/cost-breakdown', $query, 3000 - ($i * 200));
    }

    expect(app(RevivalBrief::class)->for($site, ['/cost-breakdown']))->toHaveCount(5);
});

it('still drops a query too small to shape anything', function () {
    $site = Site::factory()->create();
    briefQuery($site, '/a', 'sump pump alarm going off', 78000);
    briefQuery($site, '/a', 'why is my sump pump', 4);

    expect(app(RevivalBrief::class)->for($site, ['/a']))->toHaveCount(1);
});
