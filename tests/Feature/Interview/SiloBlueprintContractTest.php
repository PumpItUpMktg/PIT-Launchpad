<?php

use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

test('a blueprint owns spokes with enum-cast contract fields', function () {
    $site = Site::factory()->create();
    $blueprint = SiloBlueprint::factory()->create([
        'site_id' => $site->id,
        'trade' => 'waterproofing',
        'seed' => ['trade' => 'waterproofing', 'anchor_services' => ['Sump Pump Installation']],
    ]);

    $pillar = Spoke::factory()->create([
        'silo_blueprint_id' => $blueprint->id,
        'site_id' => $site->id,
        'silo' => 'Basement Waterproofing',
        'is_pillar' => true,
        'name' => 'Basement Waterproofing',
        'page_type' => SpokePageType::Service,
        'tag' => SpokeTag::Core,
        'status' => SpokeStatus::Offered,
        'granularity' => SpokeGranularity::OwnPage,
        'volume' => 1300,
    ]);

    Spoke::factory()->create([
        'silo_blueprint_id' => $blueprint->id,
        'site_id' => $site->id,
        'silo' => 'Basement Waterproofing',
        'name' => 'Gutter Repair',
        'page_type' => SpokePageType::Content,
        'tag' => SpokeTag::Connecting,
        'status' => SpokeStatus::Content,
        'connection_note' => 'gutters — a cause of basement water',
    ]);

    expect($blueprint->seed)->toBeArray()
        ->and($blueprint->spokes()->count())->toBe(2)
        ->and($blueprint->pillars()->count())->toBe(1);

    $pillar->refresh();
    expect($pillar->page_type)->toBe(SpokePageType::Service)
        ->and($pillar->tag)->toBe(SpokeTag::Core)
        ->and($pillar->status)->toBe(SpokeStatus::Offered)
        ->and($pillar->granularity)->toBe(SpokeGranularity::OwnPage)
        ->and($pillar->is_pillar)->toBeTrue()
        ->and($pillar->volume)->toBe(1300);

    $connecting = Spoke::where('name', 'Gutter Repair')->first();
    expect($connecting->tag)->toBe(SpokeTag::Connecting)
        ->and($connecting->page_type)->toBe(SpokePageType::Content)
        ->and($connecting->connection_note)->toBe('gutters — a cause of basement water')
        ->and($connecting->blueprint->is($blueprint))->toBeTrue();
});
