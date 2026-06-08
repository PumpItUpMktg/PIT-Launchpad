<?php

use App\Models\Account;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;

test('an account has many sites', function () {
    $account = Account::factory()->create();
    Site::factory()->count(2)->for($account)->create();

    expect($account->sites)->toHaveCount(2)
        ->and($account->sites->first()->account->is($account))->toBeTrue();
});

test('silos and services share a many-to-many relationship', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $service = Service::factory()->create(['site_id' => $site->id]);

    $silo->services()->attach($service);

    expect($silo->services)->toHaveCount(1)
        ->and($service->silos)->toHaveCount(1)
        ->and($silo->services->first()->is($service))->toBeTrue();
});

test('a silo can nest under a parent silo', function () {
    $site = Site::factory()->create();
    $parent = Silo::factory()->servicePillar()->create(['site_id' => $site->id]);
    $child = Silo::factory()->topical()->create([
        'site_id' => $site->id,
        'parent_silo_id' => $parent->id,
    ]);

    expect($child->parent->is($parent))->toBeTrue()
        ->and($parent->children)->toHaveCount(1);
});

test('content belongs to a silo and targets a keyword', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id]);

    $content = Content::factory()->create([
        'site_id' => $site->id,
        'silo_id' => $silo->id,
        'target_keyword_id' => $keyword->id,
    ]);

    expect($content->silo->is($silo))->toBeTrue()
        ->and($content->targetKeyword->is($keyword))->toBeTrue()
        ->and($keyword->contents->first()->is($content))->toBeTrue();
});

test('content versions are linked to their content', function () {
    $site = Site::factory()->create();
    $content = Content::factory()->create(['site_id' => $site->id]);

    ContentVersion::factory()
        ->count(2)
        ->sequence(['version' => 1], ['version' => 2])
        ->create(['content_id' => $content->id]);

    expect($content->versions)->toHaveCount(2);
});

test('services and markets relate many-to-many', function () {
    $site = Site::factory()->create();
    $service = Service::factory()->create(['site_id' => $site->id]);
    $market = Market::factory()->priority()->create(['site_id' => $site->id]);

    $service->markets()->attach($market);

    expect($service->markets)->toHaveCount(1)
        ->and($market->services->first()->is($service))->toBeTrue();
});
