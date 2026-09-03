<?php

use App\Build\DuplicateHubReport;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;

function hub(Site $site, Silo $silo, ContentStatus $status, array $attrs = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Hub,
        'silo_id' => $silo->id,
        'status' => $status,
    ], $attrs));
}

it('groups duplicate hubs by silo, names the keeper, and classifies removable vs blocked', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);

    $keeper = hub($site, $silo, ContentStatus::Published, ['slug' => 'sump-pumps']);       // published → keeper
    $blocked = hub($site, $silo, ContentStatus::NeedsReview, ['slug' => 'sump-pumps-2', 'slot_payload' => ['hero' => 'x']]); // drafted → blocked
    $removable = hub($site, $silo, ContentStatus::Candidate, ['slug' => 'sump-pumps-3', 'slot_payload' => null]); // empty → removable

    // A child spoke nested under the REMOVABLE (non-keeper) hub — a cleanup would re-point it.
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'silo_id' => $silo->id, 'parent_content_id' => $removable->id,
    ]);

    $groups = app(DuplicateHubReport::class)->forSite($site);

    expect($groups)->toHaveCount(1);
    $g = $groups[0];
    expect($g['silo_name'])->toBe('Sump Pumps')
        ->and($g['total'])->toBe(3)
        ->and($g['keeper']['id'])->toBe($keeper->id)
        ->and($g['removable'])->toHaveCount(1)
        ->and($g['removable'][0]['id'])->toBe($removable->id)
        ->and($g['blocked'])->toHaveCount(1)
        ->and($g['blocked'][0]['id'])->toBe($blocked->id)
        ->and($g['children_to_repoint'])->toBe(1);
});

it('ignores a silo with a single hub and hubs with no silo', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);

    hub($site, $silo, ContentStatus::Published); // only one for this silo
    Content::factory()->create([                  // hub with no silo — no group
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Hub, 'silo_id' => null,
    ]);

    expect(app(DuplicateHubReport::class)->forSite($site))->toBe([]);
});

it('changes nothing — it is read-only', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    hub($site, $silo, ContentStatus::Published);
    hub($site, $silo, ContentStatus::Candidate);

    $before = Content::withoutGlobalScopes()->count();
    app(DuplicateHubReport::class)->forSite($site);
    $this->artisan('launchpad:report-duplicate-hubs', ['--site' => $site->id])->assertSuccessful();

    expect(Content::withoutGlobalScopes()->count())->toBe($before); // no soft-deletes, no writes
});

it('reports a clean portfolio with a friendly message', function () {
    Site::factory()->create();

    $this->artisan('launchpad:report-duplicate-hubs')
        ->expectsOutputToContain('No duplicate silo hubs found')
        ->assertSuccessful();
});
