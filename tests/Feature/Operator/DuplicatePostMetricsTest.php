<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Operator\Coverage\DuplicatePostMetrics;
use Illuminate\Support\Facades\Artisan;

function dupPost(Site $s, string $title, string $slug, string $status, ?string $publishedAt): Content
{
    return Content::factory()->create([
        'site_id' => $s->id, 'kind' => ContentKind::Post, 'page_type' => null,
        'status' => $status, 'title' => $title, 'slug' => $slug,
        'published_at' => $publishedAt, 'body' => '<p>x</p>',
    ]);
}

function dupGscDay(Site $s, string $url, string $date, int $impr, int $clicks, ?float $pos): void
{
    GscUrlDaily::create([
        'site_id' => $s->id, 'grain_hash' => hash('sha256', $url.'|'.$date), 'date' => $date,
        'url' => $url, 'impressions' => $impr, 'clicks' => $clicks, 'ctr' => 0, 'position' => $pos,
    ]);
}

it('groups a -N slug pair by its de-numbered base and attaches GSC to both sides', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $original = dupPost($site, '$2.2M Sewer Grants in Northern Chester County', 'sewer-grants-chester-what', 'published', '2026-08-01');
    $twin = dupPost($site, '$2.2M Sewer Grants in Northern Chester County', 'sewer-grants-chester-what-2', 'published', '2026-08-20');

    // The original earns; the -2 twin is dead. Blended position = (100*5 + 100*7)/200 = 6.0.
    dupGscDay($site, 'https://spg.example/sewer-grants-chester-what/', '2026-09-01', 100, 4, 5.0);
    dupGscDay($site, 'https://spg.example/sewer-grants-chester-what/', '2026-09-02', 100, 6, 7.0);
    dupGscDay($site, 'https://spg.example/sewer-grants-chester-what-2/', '2026-09-01', 3, 0, 40.0);

    $report = app(DuplicatePostMetrics::class)->report($site, 3650);

    expect($report)->toHaveCount(1);
    $g = $report[0];
    $members = collect($g['members'])->keyBy('content_id');

    expect($g['key'])->toBe('sewer-grants-chester-what')
        ->and($members[$original->id]['impressions'])->toBe(200)
        ->and($members[$original->id]['clicks'])->toBe(10)
        ->and($members[$original->id]['position'])->toBe(6.0)
        ->and($members[$original->id]['numbered'])->toBeFalse()
        ->and($members[$original->id]['top_impressions'])->toBeTrue()   // the earner
        ->and($members[$twin->id]['numbered'])->toBeTrue()
        ->and($members[$twin->id]['impressions'])->toBe(3)
        ->and($members[$twin->id]['top_impressions'])->toBeFalse()
        ->and($g['earner_id'])->toBe($original->id)
        ->and($g['age_keeper_id'])->toBe($original->id)               // oldest
        ->and($g['age_conflict'])->toBeFalse();                       // earner IS the oldest here
});

it('raises age_conflict when the -N twin is the earner and the original is dead (the Buckingham lesson)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $original = dupPost($site, 'Flooding Resources Hoboken', 'flooding-resources-hoboken', 'published', '2026-06-01'); // oldest, dead
    $twin = dupPost($site, 'Flooding Resources Hoboken', 'flooding-resources-hoboken-2', 'published', '2026-07-15');   // newer, earns

    dupGscDay($site, 'https://spg.example/flooding-resources-hoboken-2/', '2026-09-01', 500, 20, 3.0);
    dupGscDay($site, 'https://spg.example/flooding-resources-hoboken/', '2026-09-01', 1, 0, 90.0);

    $g = app(DuplicatePostMetrics::class)->report($site, 3650)[0];

    expect($g['age_keeper_id'])->toBe($original->id)   // age would keep the elder…
        ->and($g['earner_id'])->toBe($twin->id)        // …but the -2 twin holds the authority
        ->and($g['age_conflict'])->toBeTrue();         // so age picks wrong — flag it
});

it('does not surface a post with no twin', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    dupPost($site, 'How to Prevent Flooding', 'how-to-prevent-flooding', 'published', '2026-08-01');

    expect(app(DuplicatePostMetrics::class)->report($site))->toBe([]);
});

it('is published-only — an unpublished candidate twin is not a live duplicate', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    dupPost($site, 'Sump Pump Maintenance Tips', 'sump-pump-maintenance-tips', 'published', '2026-08-01');
    dupPost($site, 'Sump Pump Maintenance Tips', 'sump-pump-maintenance-tips-2', ContentStatus::Candidate->value, null);

    expect(app(DuplicatePostMetrics::class)->report($site))->toBe([]); // only one is live → no live pair
});

it('honors the GSC window — impressions outside --days are excluded', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    dupPost($site, 'Radon Test Tampering', 'radon-test-tampering', 'published', '2026-08-01');
    dupPost($site, 'Radon Test Tampering', 'radon-test-tampering-2', 'published', '2026-08-02');

    dupGscDay($site, 'https://spg.example/radon-test-tampering/', now()->subDays(2)->toDateString(), 50, 1, 4.0);    // in window
    dupGscDay($site, 'https://spg.example/radon-test-tampering/', now()->subDays(400)->toDateString(), 999, 9, 4.0); // out of window

    $g = app(DuplicatePostMetrics::class)->report($site, 28)[0];
    $original = collect($g['members'])->firstWhere('numbered', false);

    expect($original['impressions'])->toBe(50); // only the in-window row counted
});

it('command prints both sides, flags the earner, and warns on an age-conflict group', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    $original = dupPost($site, 'Flooding Resources Hoboken', 'flooding-resources-hoboken', 'published', '2026-06-01');
    $twin = dupPost($site, 'Flooding Resources Hoboken', 'flooding-resources-hoboken-2', 'published', '2026-07-15');
    PageIndexState::create(['site_id' => $site->id, 'content_id' => $twin->id, 'url' => 'https://spg.example/flooding-resources-hoboken-2/', 'url_normalized' => '/flooding-resources-hoboken-2', 'index_verdict' => 'PASS']);
    dupGscDay($site, 'https://spg.example/flooding-resources-hoboken-2/', now()->subDays(1)->toDateString(), 500, 20, 3.0);

    $code = Artisan::call('launchpad:report-duplicate-posts', ['--site' => $site->id]);
    $out = Artisan::output(); // fetch once — Symfony's buffered output empties on read

    expect($code)->toBe(0)
        ->and($out)->toContain('Flooding Resources Hoboken')
        ->and($out)->toContain('/flooding-resources-hoboken-2/')
        ->and($out)->toContain('← earns')
        ->and($out)->toContain('age-conflict')
        ->and($out)->toContain('index: indexed');
});

it('reports a clean tenant as a real "nothing" result', function () {
    $site = Site::factory()->create(['brand_name' => 'CleanCo', 'domain_url' => 'https://clean.example']);
    dupPost($site, 'Winter Prep Checklist', 'winter-prep-checklist', 'published', '2026-08-01');

    $code = Artisan::call('launchpad:report-duplicate-posts', ['--site' => $site->id]);

    expect($code)->toBe(0)->and(Artisan::output())->toContain('No live duplicate blog posts found.');
});
