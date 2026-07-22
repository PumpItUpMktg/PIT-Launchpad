<?php

use App\Enums\SpokeGranularity;
use App\Enums\SpokeStatus;
use App\KeywordGenerator\Derive\ServicePageGuarantee;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

function guaranteedService(Site $site, string $name, string $silo): Service
{
    $service = Service::factory()->create(['site_id' => $site->id, 'name' => $name]);
    $service->forceFill(['force_page' => true, 'forced_silo' => $silo])->save();

    return $service;
}

it('materializes an own-page spoke for a force_page service in its topic', function () {
    $site = Site::factory()->create();
    SiloBlueprint::withoutGlobalScopes()->create(['site_id' => $site->id, 'trade' => 'waterproofing', 'seed' => ['trade' => 'waterproofing']]);
    guaranteedService($site, 'Radon Mitigation', 'Foundation Water Problems');

    $built = app(ServicePageGuarantee::class)->ensure($site);

    $spoke = Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', 'Radon Mitigation')->first();
    expect($built)->toBe(1)
        ->and($spoke)->not->toBeNull()
        ->and($spoke->silo)->toBe('Foundation Water Problems')
        ->and($spoke->granularity)->toBe(SpokeGranularity::OwnPage)
        ->and($spoke->status)->toBe(SpokeStatus::Offered);
});

it('is idempotent — a second run adds nothing, and re-adds after a rebuild dropped it', function () {
    $site = Site::factory()->create();
    SiloBlueprint::withoutGlobalScopes()->create(['site_id' => $site->id, 'trade' => 'waterproofing', 'seed' => ['trade' => 'waterproofing']]);
    guaranteedService($site, 'Sewer Line Repair & Replacement', 'Sewage & Ejector Pumps');

    expect(app(ServicePageGuarantee::class)->ensure($site))->toBe(1)
        ->and(app(ServicePageGuarantee::class)->ensure($site))->toBe(0) // already covered
        ->and(Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', 'Sewer Line Repair & Replacement')->count())->toBe(1);

    // Simulate a rebuild-from-scratch wiping the tree — the guarantee re-materializes the page.
    Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->delete();
    expect(app(ServicePageGuarantee::class)->ensure($site))->toBe(1);
});

it('never doubles a service the demand tree already gave a page', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::withoutGlobalScopes()->create(['site_id' => $site->id, 'trade' => 'waterproofing', 'seed' => ['trade' => 'waterproofing']]);
    // The derivation already built a page for it (different topic).
    Spoke::create(['silo_blueprint_id' => $bp->id, 'site_id' => $site->id, 'name' => 'Mold Testing', 'silo' => 'Symptoms & Solutions', 'status' => SpokeStatus::Offered, 'granularity' => SpokeGranularity::OwnPage]);
    guaranteedService($site, 'Mold Testing', 'Foundation Water Problems');

    expect(app(ServicePageGuarantee::class)->ensure($site))->toBe(0)
        ->and(Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', 'Mold Testing')->count())->toBe(1);
});

it('skips a force_page service with no topic pinned', function () {
    $site = Site::factory()->create();
    SiloBlueprint::withoutGlobalScopes()->create(['site_id' => $site->id, 'trade' => 'waterproofing', 'seed' => ['trade' => 'waterproofing']]);
    $service = Service::factory()->create(['site_id' => $site->id, 'name' => 'Water Damage Cleanup']);
    $service->forceFill(['force_page' => true, 'forced_silo' => null])->save();

    expect(app(ServicePageGuarantee::class)->ensure($site))->toBe(0)
        ->and(Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});
