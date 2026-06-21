<?php

use App\Build\BuildManifestAssembler;
use App\Enums\BuildSource;
use App\Enums\ProofType;
use App\Enums\SpokeGranularity;
use App\Enums\SpokeStatus;
use App\Enums\StandardPageType;
use App\Models\BuildPage;
use App\Models\CoverageArea;
use App\Models\ProofItem;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Standard\StandardPages;

test('assemble builds the manifest across standard, service, and location sources', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    // Service: a hub + an own-page core (built) + a folded section (excluded).
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'name' => 'Pumps', 'silo' => 'Pumps', 'is_pillar' => true, 'status' => SpokeStatus::Offered, 'volume' => 0]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'name' => 'Install', 'silo' => 'Pumps', 'status' => SpokeStatus::Offered, 'granularity' => SpokeGranularity::OwnPage, 'volume' => 300]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'name' => 'Section', 'silo' => 'Pumps', 'status' => SpokeStatus::Offered, 'granularity' => SpokeGranularity::Folded, 'volume' => 10]);

    // Location: one selected town.
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Newark', 'state' => 'NJ', 'page_selected' => true, 'population' => 300000]);

    // Standard: accept Reviews (gated on review proof).
    ProofItem::factory()->create(['site_id' => $site->id, 'type' => ProofType::ReviewAggregate]);
    app(StandardPages::class)->setAccepted($site, StandardPageType::Reviews, true);

    app(BuildManifestAssembler::class)->assemble($site->fresh());

    $rows = BuildPage::query()->where('site_id', $site->id)->get();

    expect($rows->where('source', BuildSource::Standard)->count())->toBe(7) // 6 fixed + Reviews
        ->and($rows->where('source', BuildSource::Service)->count())->toBe(2) // hub + own-page (folded excluded)
        ->and($rows->where('source', BuildSource::Location)->count())->toBe(1)
        ->and($rows->firstWhere('page_key', 'home')->priority)->toBe(0)
        ->and($rows->firstWhere('page_key', 'home')->review_required)->toBeTrue()       // brand-critical
        ->and($rows->firstWhere('page_key', 'reviews')->review_required)->toBeFalse();
});

test('assemble is idempotent — re-running upserts, never duplicates', function () {
    $site = Site::factory()->create();
    SiloBlueprint::factory()->create(['site_id' => $site->id]);

    app(BuildManifestAssembler::class)->assemble($site);
    $first = BuildPage::query()->where('site_id', $site->id)->count();
    app(BuildManifestAssembler::class)->assemble($site->fresh());

    expect(BuildPage::query()->where('site_id', $site->id)->count())->toBe($first)
        ->and($first)->toBe(6); // the fixed core, no optionals/services/towns
});
