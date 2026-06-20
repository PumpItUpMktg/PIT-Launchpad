<?php

use App\Enums\MediaKind;
use App\Enums\MediaSource;
use App\Enums\ProofType;
use App\Enums\StandardPageType;
use App\Models\MediaAsset;
use App\Models\ProofItem;
use App\Models\Site;
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

test('Reviews is gated on review proof', function () {
    expect($this->gate->isAvailable($this->site, StandardPageType::Reviews))->toBeFalse();

    ProofItem::factory()->create(['site_id' => $this->site->id, 'type' => ProofType::ReviewAggregate]);

    expect($this->gate->isAvailable($this->site, StandardPageType::Reviews))->toBeTrue();
});

test('Gallery is gated on enough uploaded Job Capture photos', function () {
    MediaAsset::factory()->count(2)->create(['site_id' => $this->site->id, 'kind' => MediaKind::Photo, 'source' => MediaSource::Uploaded]);
    expect($this->gate->isAvailable($this->site, StandardPageType::Gallery))->toBeFalse(); // < 3

    MediaAsset::factory()->create(['site_id' => $this->site->id, 'kind' => MediaKind::Photo, 'source' => MediaSource::Uploaded]);
    expect($this->gate->isAvailable($this->site->fresh(), StandardPageType::Gallery))->toBeTrue(); // 3
});

test('Warranty is gated on warranty proof; Financing and Team default closed', function () {
    expect($this->gate->isAvailable($this->site, StandardPageType::Warranty))->toBeFalse()
        ->and($this->gate->isAvailable($this->site, StandardPageType::Financing))->toBeFalse()
        ->and($this->gate->isAvailable($this->site, StandardPageType::Team))->toBeFalse();

    ProofItem::factory()->create(['site_id' => $this->site->id, 'type' => ProofType::Warranty]);
    expect($this->gate->isAvailable($this->site, StandardPageType::Warranty))->toBeTrue();
});

test('accept/decline persists, and forSite returns the fixed core + accepted offerable optionals', function () {
    ProofItem::factory()->create(['site_id' => $this->site->id, 'type' => ProofType::ReviewAggregate]);

    // A non-offerable optional cannot be accepted.
    $this->pages->setAccepted($this->site, StandardPageType::Gallery, true); // no photos → ignored
    $this->pages->setAccepted($this->site, StandardPageType::Reviews, true);

    $forSite = $this->pages->forSite($this->site->fresh());

    expect($forSite)->toContain(StandardPageType::Home)        // fixed
        ->toContain(StandardPageType::Reviews)                 // accepted + offerable
        ->not->toContain(StandardPageType::Gallery)            // not offerable → never accepted
        ->not->toContain(StandardPageType::Faq);               // offerable but not accepted
});
