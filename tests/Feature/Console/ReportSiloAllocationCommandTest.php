<?php

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Silo;
use App\Models\Site;

it('flags an over-served silo and an under-served (high-value, starved) silo', function () {
    $site = Site::factory()->create(['brand_name' => 'Acme']);

    // High opportunity, no candidates → the funnel is starving it.
    $starved = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Foundation Repair']);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $starved->id, 'opportunity_score' => 100]);

    // Low opportunity, a pile of candidates → drawing ingestion noise.
    $flooded = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Trivia']);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $flooded->id, 'opportunity_score' => 10]);
    Content::factory()->post()->count(5)->create(['site_id' => $site->id, 'silo_id' => $flooded->id, 'status' => ContentStatus::Candidate]);

    $this->artisan('launchpad:report-silo-allocation')
        ->assertSuccessful()
        ->expectsOutputToContain('Acme')
        ->expectsOutputToContain('OVER-served')
        ->expectsOutputToContain('UNDER-served');
});

it('honors --site and reports nothing to compare cleanly', function () {
    $site = Site::factory()->create();
    Silo::factory()->create(['site_id' => $site->id, 'name' => 'Empty Silo']); // no candidates, no opportunity

    // A silo with neither candidates nor opportunity → the site has nothing to compare, so no silo lines.
    $this->artisan('launchpad:report-silo-allocation', ['--site' => $site->id])
        ->assertSuccessful()
        ->doesntExpectOutputToContain('Empty Silo');
});

it('errors on an unknown --site', function () {
    $this->artisan('launchpad:report-silo-allocation', ['--site' => 'nope'])
        ->assertFailed()
        ->expectsOutputToContain('No site matches');
});
