<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\SiloCreator\SiloNesting;

function siloPage(Site $site, PageType $type, string $siloId, string $title, string $slug): Content
{
    return Content::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => $type,
        'title' => $title,
        'slug' => $slug,
        'version' => 1,
        'silo_id' => $siloId,
    ]);
}

it('nests a child service page under its silo hub — parent pinned + slug nested', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $hub = siloPage($site, PageType::Hub, $silo->id, 'Drain Services', 'drain-services');
    $child = siloPage($site, PageType::Service, $silo->id, 'Drain Cleaning', 'drain-cleaning');

    app(SiloNesting::class)->nest($site);

    $child->refresh();
    expect($child->parent_content_id)->toBe($hub->id)
        ->and($child->slug)->toBe('drain-services/drain-cleaning');

    // The hub itself stays top-level.
    expect($hub->fresh()->slug)->toBe('drain-services')
        ->and($hub->fresh()->parent_content_id)->toBeNull();
});

it('nests every child service in the silo, each under the same hub', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $hub = siloPage($site, PageType::Hub, $silo->id, 'Water Heaters', 'water-heaters');
    $a = siloPage($site, PageType::Service, $silo->id, 'Repair', 'repair');
    $b = siloPage($site, PageType::Service, $silo->id, 'Installation', 'installation');

    app(SiloNesting::class)->nest($site);

    expect($a->fresh()->slug)->toBe('water-heaters/repair')
        ->and($b->fresh()->slug)->toBe('water-heaters/installation')
        ->and($a->fresh()->parent_content_id)->toBe($hub->id)
        ->and($b->fresh()->parent_content_id)->toBe($hub->id);
});

it('is idempotent and self-heals a hub rename', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $hub = siloPage($site, PageType::Hub, $silo->id, 'Sewer', 'sewer');
    $child = siloPage($site, PageType::Service, $silo->id, 'Line Repair', 'line-repair');

    app(SiloNesting::class)->nest($site);
    app(SiloNesting::class)->nest($site);
    expect($child->fresh()->slug)->toBe('sewer/line-repair');

    // Rename the hub slug; re-nesting re-parents the segment under the new hub path.
    $hub->forceFill(['slug' => 'sewer-services'])->save();
    app(SiloNesting::class)->nest($site);
    expect($child->fresh()->slug)->toBe('sewer-services/line-repair');
});

it('leaves a service flat when its silo has no hub', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    // No hub (pillar) page for this silo.
    $child = siloPage($site, PageType::Service, $silo->id, 'Lonely Service', 'lonely-service');

    app(SiloNesting::class)->nest($site);

    $child->refresh();
    expect($child->slug)->toBe('lonely-service')
        ->and($child->parent_content_id)->toBeNull();
});
