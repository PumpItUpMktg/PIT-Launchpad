<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\Site;
use App\Operate\PagesBoard;

function newarkLocation(Site $site): Location
{
    return Location::factory()->create([
        'site_id' => $site->id,
        'name' => 'Newark',
        'address_components' => [
            ['types' => ['locality'], 'long_name' => 'Newark'],
            ['types' => ['administrative_area_level_1'], 'short_name' => 'NJ'],
        ],
    ]);
}

function townCard(array $work, string $title): ?array
{
    return collect($work)->first(fn (array $c): bool => ($c['title'] ?? '') === $title);
}

it('tags each town work-card with the brick-and-mortar location it belongs to', function () {
    $site = Site::factory()->create();
    $loc = newarkLocation($site);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Candidate, 'title' => 'Jersey City, NJ', 'slug' => 'jersey-city-nj',
        'location_id' => null, 'parent_location_id' => $loc->id,
    ]);

    $work = app(PagesBoard::class)->locations($site)['work'];
    $card = townCard($work, 'Jersey City, NJ');

    expect($card)->not->toBeNull()
        ->and($card['brick_mortar'])->toBe('Newark, NJ')
        ->and($card['is_brick_mortar'])->toBeFalse();
});

it('flags the location landing page itself as the brick-and-mortar row', function () {
    $site = Site::factory()->create();
    $loc = newarkLocation($site);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Candidate, 'title' => 'Newark, NJ', 'slug' => 'newark-nj',
        'location_id' => $loc->id, 'parent_location_id' => null,
    ]);

    $card = townCard(app(PagesBoard::class)->locations($site)['work'], 'Newark, NJ');

    expect($card['brick_mortar'])->toBe('Newark, NJ')
        ->and($card['is_brick_mortar'])->toBeTrue();
});

it('leaves an unassigned town untagged', function () {
    $site = Site::factory()->create();
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Candidate, 'title' => 'Orphan Town', 'slug' => 'orphan-town',
        'location_id' => null, 'parent_location_id' => null,
    ]);

    $card = townCard(app(PagesBoard::class)->locations($site)['work'], 'Orphan Town');

    expect($card['brick_mortar'])->toBeNull()
        ->and($card['is_brick_mortar'])->toBeFalse();
});
