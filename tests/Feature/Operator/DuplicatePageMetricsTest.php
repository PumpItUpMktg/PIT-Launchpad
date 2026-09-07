<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Operator\Coverage\DuplicatePageMetrics;
use Illuminate\Support\Facades\Artisan;

function locPage(Site $s, string $title, string $slug, string $status, ?string $locationId, ?string $parentId): Content
{
    return Content::factory()->create([
        'site_id' => $s->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => $status, 'title' => $title, 'slug' => $slug,
        'location_id' => $locationId, 'parent_location_id' => $parentId,
    ]);
}

function gscDay(Site $s, string $url, string $date, int $impr, int $clicks, ?float $pos): void
{
    GscUrlDaily::create([
        'site_id' => $s->id, 'grain_hash' => hash('sha256', $url.'|'.$date), 'date' => $date,
        'url' => $url, 'impressions' => $impr, 'clicks' => $clicks, 'ctr' => 0, 'position' => $pos,
    ]);
}

it('pairs a landing with its self-named town and attaches GSC impressions + blended position to each', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $loc = Location::factory()->create(['site_id' => $site->id]);

    $landing = locPage($site, 'Hoboken, NJ', 'hoboken-nj', 'published', $loc->id, null);
    $town = locPage($site, 'Hoboken, NJ', 'hoboken-nj/hoboken-nj', 'published', null, $loc->id);

    // Landing earns most impressions; blended position = (100*5 + 100*7)/200 = 6.0.
    gscDay($site, 'https://spg.example/hoboken-nj/', '2026-09-01', 100, 4, 5.0);
    gscDay($site, 'https://spg.example/hoboken-nj/', '2026-09-02', 100, 6, 7.0);
    gscDay($site, 'https://spg.example/hoboken-nj/hoboken-nj/', '2026-09-01', 10, 0, 20.0);

    $report = app(DuplicatePageMetrics::class)->report($site, 3650);

    expect($report)->toHaveCount(1);
    $members = collect($report[0]['members'])->keyBy('content_id');

    expect($report[0]['town'])->toBe('Hoboken')
        ->and($members[$landing->id]['role'])->toBe('landing')
        ->and($members[$landing->id]['impressions'])->toBe(200)
        ->and($members[$landing->id]['clicks'])->toBe(10)
        ->and($members[$landing->id]['position'])->toBe(6.0)
        ->and($members[$landing->id]['top_impressions'])->toBeTrue()   // the earner
        ->and($members[$town->id]['role'])->toBe('town')
        ->and($members[$town->id]['impressions'])->toBe(10)
        ->and($members[$town->id]['position'])->toBe(20.0)
        ->and($members[$town->id]['top_impressions'])->toBeFalse();
});

it('groups a town↔town duplicate (the Buckingham shape) under one parent', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $doylestown = Location::factory()->create(['site_id' => $site->id]);

    $a = locPage($site, 'Buckingham, PA', 'doylestown-pa/buckingham-pa', 'published', null, $doylestown->id);
    $b = locPage($site, 'Buckingham, PA', 'doylestown-pa/buckingham-pa-2', 'published', null, $doylestown->id);

    $report = app(DuplicatePageMetrics::class)->report($site);

    expect($report)->toHaveCount(1)
        ->and($report[0]['town'])->toBe('Buckingham')
        ->and(collect($report[0]['members'])->pluck('content_id')->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id])->sort()->values()->all())
        ->and(collect($report[0]['members'])->every(fn (array $m): bool => $m['role'] === 'town'))->toBeTrue();
});

it('does not surface a town with no live twin', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $loc = Location::factory()->create(['site_id' => $site->id]);
    locPage($site, 'Newark, NJ', 'newark-nj', 'published', $loc->id, null);

    expect(app(DuplicatePageMetrics::class)->report($site))->toBe([]);
});

it('ignores an unpublished page — only LIVE pairs compete', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $loc = Location::factory()->create(['site_id' => $site->id]);
    locPage($site, 'Montclair, NJ', 'montclair-nj', 'published', $loc->id, null);
    locPage($site, 'Montclair, NJ', 'montclair-nj/montclair-nj', ContentStatus::Candidate->value, null, $loc->id);

    expect(app(DuplicatePageMetrics::class)->report($site))->toBe([]); // the town isn't live → no live pair
});

it('honors the GSC window — impressions outside --days are excluded', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $loc = Location::factory()->create(['site_id' => $site->id]);
    locPage($site, 'Reading, PA', 'reading-pa', 'published', $loc->id, null);
    locPage($site, 'Reading, PA', 'reading-pa/reading-pa', 'published', null, $loc->id);

    gscDay($site, 'https://spg.example/reading-pa/', now()->subDays(2)->toDateString(), 50, 1, 4.0);   // in window
    gscDay($site, 'https://spg.example/reading-pa/', now()->subDays(400)->toDateString(), 999, 9, 4.0); // out of window

    $report = app(DuplicatePageMetrics::class)->report($site, 28);
    $landing = collect($report[0]['members'])->firstWhere('role', 'landing');

    expect($landing['impressions'])->toBe(50); // only the in-window row counted
});

it('command prints both sides with the earner flagged', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    $loc = Location::factory()->create(['site_id' => $site->id]);
    $landing = locPage($site, 'Hoboken, NJ', 'hoboken-nj', 'published', $loc->id, null);
    locPage($site, 'Hoboken, NJ', 'hoboken-nj/hoboken-nj', 'published', null, $loc->id);
    PageIndexState::create(['site_id' => $site->id, 'content_id' => $landing->id, 'url' => 'https://spg.example/hoboken-nj/', 'url_normalized' => '/hoboken-nj', 'index_verdict' => 'PASS']);
    gscDay($site, 'https://spg.example/hoboken-nj/', now()->subDays(1)->toDateString(), 120, 3, 6.0);

    $code = Artisan::call('launchpad:report-duplicate-page-metrics', ['--site' => $site->id]);
    $out = Artisan::output(); // fetch once — Symfony's buffered output empties on read

    expect($code)->toBe(0)
        ->and($out)->toContain('Hoboken')
        ->and($out)->toContain('/hoboken-nj/')
        ->and($out)->toContain('← earns')
        ->and($out)->toContain('index: indexed');
});

it('reports a clean tenant as a real "nothing" result', function () {
    $site = Site::factory()->create(['brand_name' => 'CleanCo', 'domain_url' => 'https://clean.example']);
    $loc = Location::factory()->create(['site_id' => $site->id]);
    locPage($site, 'Trenton, NJ', 'trenton-nj', 'published', $loc->id, null);

    $code = Artisan::call('launchpad:report-duplicate-page-metrics', ['--site' => $site->id]);

    expect($code)->toBe(0)->and(Artisan::output())->toContain('No live duplicate location pairs found.');
});
