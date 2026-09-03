<?php

use App\Jobs\GeocodeReview;
use App\Models\Review;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Queue;

it('dry-run reports resolvable/unresolvable and queues nothing; the real run queues one geocode per resolvable review', function () {
    Queue::fake();
    $site = Site::factory()->create();
    CurrentSite::set($site->id);

    Review::factory()->for($site)->create(['town' => 'Trooper', 'service_address' => null, 'lat' => null, 'lng' => null]);      // resolvable (town)
    Review::factory()->for($site)->create(['town' => null, 'service_address' => '1 Main St, Belleville NJ', 'lat' => null]);      // resolvable (address)
    Review::factory()->for($site)->create(['town' => null, 'service_address' => null, 'lat' => null, 'lng' => null]);            // unresolvable
    Review::factory()->for($site)->create(['town' => 'X', 'lat' => 40.0, 'lng' => -74.0]);                                        // already has a point → skipped

    $this->artisan('launchpad:backfill-review-geo', ['--dry-run' => true])
        ->expectsOutputToContain('3 review(s) missing a point: 2 resolvable')
        ->assertSuccessful();
    Queue::assertNothingPushed();

    $this->artisan('launchpad:backfill-review-geo')->assertSuccessful();
    Queue::assertPushed(GeocodeReview::class, 2);
});
