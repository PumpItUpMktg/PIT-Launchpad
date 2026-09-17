<?php

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;

it('times each stage of the board build and says what each one produced', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://spg.com']);
    $shown = Location::factory()->create(['site_id' => $site->id, 'name' => 'Doylestown', 'lat' => 40.31, 'lng' => -75.13]);
    $other = Location::factory()->create(['site_id' => $site->id, 'name' => 'Newtown', 'lat' => 40.22, 'lng' => -74.93]);
    Content::factory()->page()->published()->create(['site_id' => $site->id, 'page_type' => PageType::Location,
        'status' => ContentStatus::Published, 'parent_location_id' => $shown->id, 'slug' => 'chalfont-pa', 'title' => 'Chalfont']);
    // One page still in progress, so the work lane has something to count.
    Content::factory()->page()->create(['site_id' => $site->id, 'page_type' => PageType::Location,
        'status' => ContentStatus::Drafted, 'parent_location_id' => $shown->id, 'slug' => 'warrington-pa', 'title' => 'Warrington']);

    expect(Artisan::call('launchpad:pages-board-probe', ['site' => 'Sump Pump Gurus', '--location' => 'Doylestown']))->toBe(0);
    $out = Artisan::output();

    expect($out)->toContain('locations: 2')
        ->toContain('building tab: Doylestown')
        ->toContain('work lane')
        ->toContain('live groups, ALL locations')
        ->toContain('live groups, one location')
        ->toContain('whole board')
        ->toContain('Queries')
        ->toContain('town cards built: 1');   // only the tab's own cards are built
});

it('fails clearly on an unknown site', function () {
    expect(Artisan::call('launchpad:pages-board-probe', ['site' => 'no-such-brand']))->toBe(1);
    expect(Artisan::output())->toContain('No site matches');
});
