<?php

use App\ContentEngine\Reconcile\PostTownTagger;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\ContentTown;
use App\Models\CoverageArea;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;

function ttSite(): Site
{
    $site = Site::factory()->create();
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'New Brunswick']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Edison']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Brunswick, NJ']);

    return $site;
}

function ttPost(Site $site, string $title, string $body): Content
{
    return Content::factory()->post()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post,
        'status' => ContentStatus::Published, 'title' => $title, 'body' => $body,
    ]);
}

function ttTowns(Content $post): array
{
    return ContentTown::query()->where('content_id', $post->id)->pluck('town_display')->sort()->values()->all();
}

it('tags a post with every coverage town it mentions', function () {
    $site = ttSite();
    $post = ttPost($site, 'Water main break in Edison', 'Crews also responded in New Brunswick this week.');

    $result = app(PostTownTagger::class)->tag($site);

    expect(ttTowns($post))->toBe(['Edison', 'New Brunswick'])
        ->and($result['posts_tagged'])->toBe(1)
        ->and($result['tags_added'])->toBe(2);
});

it('tagPost tags a SINGLE post and returns the changed towns (idempotent on re-run)', function () {
    $site = ttSite();
    $post = ttPost($site, 'Water main break in Edison', 'Crews also helped out in New Brunswick.');

    $changed = app(PostTownTagger::class)->tagPost($post);

    expect(collect($changed)->sort()->values()->all())->toBe(['edison', 'new brunswick'])
        ->and(ttTowns($post))->toBe(['Edison', 'New Brunswick'])
        // Re-running tags nothing new — no change.
        ->and(app(PostTownTagger::class)->tagPost($post->fresh()))->toBe([]);
});

it('prefers the longer town name over a substring town', function () {
    $site = ttSite();
    // "New Brunswick" must win the alternation over "Brunswick" — not double-tag.
    $post = ttPost($site, 'A story', 'Something happened in New Brunswick today.');

    app(PostTownTagger::class)->tag($site);

    expect(ttTowns($post))->toBe(['New Brunswick']);
});

it('does not tag a town outside the site coverage set', function () {
    $site = ttSite();
    $post = ttPost($site, 'Elsewhere', 'A pipe burst in Trenton, far from here.');

    app(PostTownTagger::class)->tag($site);

    expect(ttTowns($post))->toBe([]);
});

it('is idempotent and drops a town no longer referenced', function () {
    $site = ttSite();
    $post = ttPost($site, 'Edison news', 'Work in Edison and New Brunswick.');
    app(PostTownTagger::class)->tag($site);
    expect(ttTowns($post))->toBe(['Edison', 'New Brunswick']);

    // The body is edited to drop New Brunswick; re-running syncs the town set down.
    $post->update(['body' => 'Just Edison now.']);
    $result = app(PostTownTagger::class)->tag($site);

    expect(ttTowns($post))->toBe(['Edison'])
        ->and($result['tags_removed'])->toBe(1)
        ->and($result['tags_added'])->toBe(0);
});

it('caps tags to the dominant county+state cluster — a post stays relevant to one locale', function () {
    $site = Site::factory()->create();
    // Five Middlesex-County NJ towns…
    foreach (['Edison', 'Woodbridge', 'Piscataway', 'Metuchen', 'Highland Park'] as $i => $name) {
        CoverageArea::factory()->create([
            'site_id' => $site->id, 'name' => $name, 'state' => 'NJ',
            'geo_id' => '34023'.str_pad((string) (10 + $i), 5, '0', STR_PAD_LEFT),
        ]);
    }
    // …and one Chester-County PA town the post also names.
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Downingtown', 'state' => 'PA', 'geo_id' => '4202912345']);

    $post = ttPost($site, 'Storm recap', 'Damage in Edison, Woodbridge, Piscataway, Metuchen and Highland Park — plus Downingtown, PA.');
    app(PostTownTagger::class)->tag($site);

    $towns = ttTowns($post);
    expect($towns)->not->toContain('Downingtown')  // cross-state locale dropped
        ->and(count($towns))->toBe(4);             // capped at 4 within the dominant NJ county
});

it('the --towns command option tags the site posts', function () {
    $site = Site::factory()->create(['brand_name' => 'TownCo']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Cranford']);
    $post = ttPost($site, 'Cranford update', 'News from Cranford.');

    Artisan::call('launchpad:reconcile-post-silos', ['site' => $site->id, '--towns' => true]);

    expect(Artisan::output())->toContain('1 post(s) tagged')
        ->and(ttTowns($post))->toBe(['Cranford']);
});
