<?php

use App\Build\DuplicateTownSweeper;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Str;

/** Re-fetch honoring the soft-delete scope, so gone-means-null. */
function sweptLive(string $id): ?Content
{
    return Content::withoutGlobalScope(SiteScope::class)->find($id);
}

function sweeperTown(Site $site, string $parentLocationId, string $title, ContentStatus $status = ContentStatus::Candidate, array $extra = []): Content
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
        'slot_payload' => [], // undrafted by default — hasDraft() is false (a draft sets this)
        'body' => null,
    ], $extra));
}

it('sweeps the undrafted duplicate town pages down to one, leaving a different town alone', function () {
    $site = Site::factory()->create();
    $loc = (string) Str::ulid();

    // Four undrafted rows for the SAME town (the bristol-pa-2..-6 shape); the oldest is the keeper.
    $keep = sweeperTown($site, $loc, 'Bristol, PA');
    $keep->forceFill(['created_at' => now()->subDays(5)])->save();
    $dupeA = sweeperTown($site, $loc, 'Bristol, PA');
    $dupeB = sweeperTown($site, $loc, 'Bristol');           // same townKey after normalization
    $dupeC = sweeperTown($site, $loc, 'Bristol, PA');
    // A genuinely different town under the same location — untouched.
    $other = sweeperTown($site, $loc, 'Newtown, PA');

    $removed = app(DuplicateTownSweeper::class)->sweep($site->fresh());

    expect($removed)->toBe(3)
        ->and(sweptLive($keep->id))->not->toBeNull()   // the canonical stays
        ->and(sweptLive($dupeA->id))->toBeNull()
        ->and(sweptLive($dupeB->id))->toBeNull()
        ->and(sweptLive($dupeC->id))->toBeNull()
        ->and(sweptLive($other->id))->not->toBeNull(); // the other town stays
});

it('never removes a published or drafted-in-review duplicate — only the empty extras', function () {
    $site = Site::factory()->create();
    $loc = (string) Str::ulid();

    // A live page + a drafted-in-review page + two empty extras, all the SAME town.
    $live = sweeperTown($site, $loc, 'Warminster, PA', ContentStatus::Published, ['wp_post_id' => 123]);
    $drafted = sweeperTown($site, $loc, 'Warminster, PA', ContentStatus::NeedsReview, ['slot_payload' => ['hero_heading' => 'Warminster sump pump experts']]);
    $empty1 = sweeperTown($site, $loc, 'Warminster, PA');
    $empty2 = sweeperTown($site, $loc, 'Warminster, PA');

    $removed = app(DuplicateTownSweeper::class)->sweep($site->fresh());

    // Both empties go; the live + drafted pages are BOTH kept (no drafted/live work destroyed).
    expect($removed)->toBe(2)
        ->and(sweptLive($live->id))->not->toBeNull()
        ->and(sweptLive($drafted->id))->not->toBeNull()
        ->and(sweptLive($empty1->id))->toBeNull()
        ->and(sweptLive($empty2->id))->toBeNull();
});

it('leaves a lone town page alone', function () {
    $site = Site::factory()->create();
    $loc = (string) Str::ulid();
    $only = sweeperTown($site, $loc, 'Doylestown, PA');

    expect(app(DuplicateTownSweeper::class)->sweep($site->fresh()))->toBe(0)
        ->and(sweptLive($only->id))->not->toBeNull();
});
