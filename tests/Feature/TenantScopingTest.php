<?php

use App\Models\Content;
use App\Models\Service;
use App\Models\Site;
use App\Models\WireframeKit;
use App\Support\CurrentSite;

afterEach(function () {
    CurrentSite::clear();
});

test('the site scope hides records belonging to other tenants', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();

    Content::factory()->count(2)->create(['site_id' => $siteA->id]);
    Content::factory()->count(3)->create(['site_id' => $siteB->id]);

    CurrentSite::set($siteA->id);
    expect(Content::count())->toBe(2);

    CurrentSite::set($siteB->id);
    expect(Content::count())->toBe(3);
});

test('a record from another site is not retrievable while scoped', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();

    $foreign = Content::factory()->create(['site_id' => $siteB->id]);

    CurrentSite::set($siteA->id);

    expect(Content::find($foreign->id))->toBeNull();
    expect(Content::withoutGlobalScopes()->find($foreign->id))->not->toBeNull();
});

test('no scope is applied when no site is resolved', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();

    Service::factory()->create(['site_id' => $siteA->id]);
    Service::factory()->create(['site_id' => $siteB->id]);

    CurrentSite::clear();

    expect(Service::count())->toBe(2);
});

test('site_id is auto-filled from the resolved site on create', function () {
    $site = Site::factory()->create();

    CurrentSite::set($site->id);

    $service = Service::factory()->create(['site_id' => null]);

    expect($service->site_id)->toBe($site->id);
});

test('global records without a site are never hidden by the scope', function () {
    $site = Site::factory()->create();

    $kit = WireframeKit::factory()->create(['site_id' => null]);

    CurrentSite::set($site->id);

    expect(WireframeKit::whereKey($kit->id)->exists())->toBeTrue();
});
