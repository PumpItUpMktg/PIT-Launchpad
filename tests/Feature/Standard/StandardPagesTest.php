<?php

use App\Enums\MediaKind;
use App\Enums\MediaSource;
use App\Enums\ProofType;
use App\Enums\StandardPageType;
use App\Models\MediaAsset;
use App\Models\ProofItem;
use App\Models\Site;
use App\Models\WireframeKit;
use App\Standard\StandardPageGate;
use App\Standard\StandardPages;

beforeEach(function () {
    $this->gate = app(StandardPageGate::class);
    $this->pages = app(StandardPages::class);
    $this->site = Site::factory()->create();
});

test('fixed pages are always available; FAQ and Why Choose Us are always offerable', function () {
    foreach (StandardPageType::fixed() as $fixed) {
        expect($this->gate->isAvailable($this->site, $fixed))->toBeTrue();
    }
    expect($this->gate->isAvailable($this->site, StandardPageType::Faq))->toBeTrue()
        ->and($this->gate->isAvailable($this->site, StandardPageType::WhyChooseUs))->toBeTrue();
});

test('buildability guard: subtypes with no kit AND no composer are never offered — even with their data', function () {
    // reviews / gallery / warranty / financing / team have neither a wireframe kit nor a block
    // composer branch, so they would only ever build a flat, undraftable page. The guard blocks
    // them regardless of the data that would otherwise gate them in.
    ProofItem::factory()->create(['site_id' => $this->site->id, 'type' => ProofType::ReviewAggregate]);
    ProofItem::factory()->create(['site_id' => $this->site->id, 'type' => ProofType::Warranty]);
    MediaAsset::factory()->count(3)->create(['site_id' => $this->site->id, 'kind' => MediaKind::Photo, 'source' => MediaSource::Uploaded]);

    $site = $this->site->fresh();
    expect($this->gate->isAvailable($site, StandardPageType::Reviews))->toBeFalse()
        ->and($this->gate->isAvailable($site, StandardPageType::Gallery))->toBeFalse()
        ->and($this->gate->isAvailable($site, StandardPageType::Warranty))->toBeFalse()
        ->and($this->gate->isAvailable($site, StandardPageType::Financing))->toBeFalse()
        ->and($this->gate->isAvailable($site, StandardPageType::Team))->toBeFalse();
});

test('the guard is data-driven: seeding a reviews-page kit re-enables the review-proof gate', function () {
    WireframeKit::factory()->create(['name' => 'reviews-page', 'page_type' => 'utility']);

    expect($this->gate->isAvailable($this->site, StandardPageType::Reviews))->toBeFalse(); // kit present, no proof
    ProofItem::factory()->create(['site_id' => $this->site->id, 'type' => ProofType::ReviewAggregate]);
    expect($this->gate->isAvailable($this->site->fresh(), StandardPageType::Reviews))->toBeTrue(); // kit + proof
});

test('with a gallery-page kit seeded, Gallery follows its photo-count gate', function () {
    WireframeKit::factory()->create(['name' => 'gallery-page', 'page_type' => 'utility']);

    MediaAsset::factory()->count(2)->create(['site_id' => $this->site->id, 'kind' => MediaKind::Photo, 'source' => MediaSource::Uploaded]);
    expect($this->gate->isAvailable($this->site, StandardPageType::Gallery))->toBeFalse(); // < 3

    MediaAsset::factory()->create(['site_id' => $this->site->id, 'kind' => MediaKind::Photo, 'source' => MediaSource::Uploaded]);
    expect($this->gate->isAvailable($this->site->fresh(), StandardPageType::Gallery))->toBeTrue(); // 3
});

test('with a warranty-page kit seeded, Warranty follows its proof gate; Financing/Team stay closed', function () {
    WireframeKit::factory()->create(['name' => 'warranty-page', 'page_type' => 'utility']);
    WireframeKit::factory()->create(['name' => 'financing-page', 'page_type' => 'utility']);
    WireframeKit::factory()->create(['name' => 'team-page', 'page_type' => 'utility']);

    expect($this->gate->isAvailable($this->site, StandardPageType::Warranty))->toBeFalse()   // kit, no proof
        ->and($this->gate->isAvailable($this->site, StandardPageType::Financing))->toBeFalse() // intake flag closed
        ->and($this->gate->isAvailable($this->site, StandardPageType::Team))->toBeFalse();      // intake flag closed

    ProofItem::factory()->create(['site_id' => $this->site->id, 'type' => ProofType::Warranty]);
    expect($this->gate->isAvailable($this->site->fresh(), StandardPageType::Warranty))->toBeTrue();
});

test('accept/decline persists, and forSite returns the fixed core + accepted offerable optionals', function () {
    // A non-offerable optional cannot be accepted (Gallery: no photos AND unbuildable).
    $this->pages->setAccepted($this->site, StandardPageType::Gallery, true);       // ignored
    $this->pages->setAccepted($this->site, StandardPageType::WhyChooseUs, true);   // offerable (kit+composer) → accepted

    $forSite = $this->pages->forSite($this->site->fresh());

    expect($forSite)->toContain(StandardPageType::Home)        // fixed
        ->toContain(StandardPageType::WhyChooseUs)             // accepted + offerable
        ->not->toContain(StandardPageType::Gallery)            // not offerable → never accepted
        ->not->toContain(StandardPageType::Faq);               // offerable but not accepted
});
