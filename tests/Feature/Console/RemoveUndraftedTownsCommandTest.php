<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function undraftedTown(Site $site, string $parentLocationId, string $title, string $slug, array $extra = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Location,
        'location_id' => null,
        'primary_service_id' => null,
        'parent_location_id' => $parentLocationId,
        'title' => $title,
        'slug' => $slug,
        'status' => ContentStatus::Candidate,
        'slot_payload' => [],   // undrafted — no draft
    ], $extra));
}

function rutLive(string $id): ?Content
{
    return Content::withoutGlobalScope(SiteScope::class)->find($id);
}

it('removes undrafted town pages + their plan rows on --apply, leaving drafted/published alone', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $loc = (string) Str::ulid();

    $ready = undraftedTown($site, $loc, 'Mount Olive, NJ', 'bedminster-nj/mount-olive-nj-2');
    BuildPage::factory()->create(['site_id' => $site->id, 'content_id' => $ready->id]);
    DB::table('jobs')->insert(['queue' => 'default', 'attempts' => 0, 'reserved_at' => null,
        'available_at' => now()->timestamp, 'created_at' => now()->timestamp, 'payload' => '{"data":{"command":"...'.$ready->id.'..."}}']);

    // A DRAFTED town (has slot_payload) and a PUBLISHED town — both must survive.
    $drafted = undraftedTown($site, $loc, 'Edison, NJ', 'bedminster-nj/edison-nj', ['slot_payload' => ['hero' => 'x']]);
    $live = undraftedTown($site, $loc, 'Bernards, NJ', 'bedminster-nj/bernards-nj', ['status' => ContentStatus::Published, 'slot_payload' => ['hero' => 'y']]);

    Artisan::call('launchpad:remove-undrafted-towns', ['site' => 'SPG', '--apply' => true]);

    expect(rutLive($ready->id))->toBeNull()                                                   // wiped
        ->and(BuildPage::withoutGlobalScope(SiteScope::class)->where('content_id', $ready->id)->count())->toBe(0) // plan row gone
        ->and(DB::table('jobs')->where('payload', 'like', '%'.$ready->id.'%')->count())->toBe(0)  // job flushed
        ->and(rutLive($drafted->id))->not->toBeNull()                                          // drafted survives
        ->and(rutLive($live->id))->not->toBeNull();                                            // published survives
});

it('previews without changing anything by default', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $loc = (string) Str::ulid();
    $ready = undraftedTown($site, $loc, 'Hillsborough, NJ', 'bedminster-nj/hillsborough-nj-5');

    Artisan::call('launchpad:remove-undrafted-towns', ['site' => 'SPG']);

    expect(rutLive($ready->id))->not->toBeNull()
        ->and(Artisan::output())->toContain('Preview only');
});

it('--location scopes the wipe to one GBP location', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $bedminster = (string) Str::ulid();
    $newBrunswick = (string) Str::ulid();
    $a = undraftedTown($site, $bedminster, 'Mount Olive, NJ', 'bedminster-nj/mount-olive-nj-3');
    $b = undraftedTown($site, $newBrunswick, 'Edison, NJ', 'new-brunswick-nj/edison-nj-2');

    Artisan::call('launchpad:remove-undrafted-towns', ['site' => 'SPG', '--location' => $bedminster, '--apply' => true]);

    expect(rutLive($a->id))->toBeNull()          // in-scope, wiped
        ->and(rutLive($b->id))->not->toBeNull(); // other location, untouched
});

it('reports nothing to remove on a clean tenant', function () {
    $site = Site::factory()->create(['brand_name' => 'Clean']);
    undraftedTown($site, (string) Str::ulid(), 'Westfield, NJ', 'x/westfield-nj', ['slot_payload' => ['hero' => 'z']]); // drafted

    Artisan::call('launchpad:remove-undrafted-towns', ['site' => 'Clean', '--apply' => true]);

    expect(Artisan::output())->toContain('nothing to remove');
});
