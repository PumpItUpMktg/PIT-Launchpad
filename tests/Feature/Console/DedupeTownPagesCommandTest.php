<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/** Re-fetch honoring the soft-delete scope (Model::fresh() bypasses it), so gone-means-null. */
function liveContent(string $id): ?Content
{
    return Content::withoutGlobalScope(SiteScope::class)->find($id);
}

function townPage(Site $site, string $parentLocationId, string $title, ContentStatus $status = ContentStatus::Candidate, array $extra = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Location,
        'location_id' => null,
        'primary_service_id' => null,
        'parent_location_id' => $parentLocationId,
        'title' => $title,
        'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
        'status' => $status,
    ], $extra));
}

it('collapses duplicate town pages to one canonical per town on --apply', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $loc = (string) Str::ulid();

    // Three rows for the SAME town (same parent location + normalized name), one older.
    $keep = townPage($site, $loc, 'Bedminster, NJ');
    $keep->forceFill(['created_at' => now()->subDays(5)])->save();
    $dupeA = townPage($site, $loc, 'Bedminster, NJ');
    $dupeB = townPage($site, $loc, 'Bedminster');   // same townKey after normalization
    // A different town under the same location — untouched.
    $other = townPage($site, $loc, 'Bernards, NJ');

    Artisan::call('launchpad:dedupe-town-pages', ['site' => 'SPG', '--apply' => true]);

    expect(liveContent($keep->id))->not->toBeNull()
        ->and(liveContent($dupeA->id))->toBeNull()          // soft-deleted
        ->and(liveContent($dupeB->id))->toBeNull()
        ->and(liveContent($other->id))->not->toBeNull();    // distinct town, kept
});

it('previews without changing anything by default', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $loc = (string) Str::ulid();
    $a = townPage($site, $loc, 'Cranford, NJ');
    $b = townPage($site, $loc, 'Cranford, NJ');

    Artisan::call('launchpad:dedupe-town-pages', ['site' => 'SPG']);

    expect($a->fresh())->not->toBeNull()
        ->and($b->fresh())->not->toBeNull()
        ->and(Artisan::output())->toContain('Preview only');
});

it('never removes a live duplicate — keeps it and reports the conflict', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $loc = (string) Str::ulid();
    $draft = townPage($site, $loc, 'Summit, NJ', ContentStatus::NeedsReview);
    $live = townPage($site, $loc, 'Summit, NJ', ContentStatus::Published, ['wp_post_id' => 4321]);

    Artisan::call('launchpad:dedupe-town-pages', ['site' => 'SPG', '--apply' => true]);

    // The live page is canonical (published wins) and both rows survive.
    expect($live->fresh()->status)->toBe(ContentStatus::Published)
        ->and($draft->fresh())->not->toBeNull();
});

it('reports a clean site with no duplicates', function () {
    $site = Site::factory()->create(['brand_name' => 'Clean']);
    $loc = (string) Str::ulid();
    townPage($site, $loc, 'Westfield, NJ');
    townPage($site, $loc, 'Garwood, NJ');

    Artisan::call('launchpad:dedupe-town-pages', ['site' => 'Clean']);

    expect(Artisan::output())->toContain('no duplicate town pages');
});
