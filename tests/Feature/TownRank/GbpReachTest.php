<?php

use App\Integrations\Places\MockPlacesProvider;
use App\Jobs\ResolveCompetitorPlaces;
use App\Models\CompetitorPlace;
use App\TownRank\CompetitorPlaceResolver;
use App\TownRank\GbpReach;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/** Towns east of a listing at (40.85, -74.83): the distance is the longitude gap × ~52.3 mi per degree at this latitude. */
function reachTown(string $name, float $milesEast, int $population, ?int $rank, array $competitors = [], bool $measured = true): array
{
    return [
        'id' => strtolower($name), 'name' => $name, 'population' => $population,
        'lat' => 40.85, 'lng' => -74.83 + $milesEast / (69.172 * cos(deg2rad(40.85))),
        'rank' => $rank, 'measured' => $measured, 'competitors' => $competitors,
    ];
}

it('bands pack presence by distance, population-weighted, and finds the reach radius and the addressable share', function () {
    $towns = [
        reachTown('Near', 2, 10000, 1),
        reachTown('Close', 4, 5000, 2),
        reachTown('Mid', 7, 8000, 5),
        reachTown('MidIn', 9, 6000, 3),
        reachTown('Far', 12, 20000, null),
        reachTown('Farther', 18, 30000, null),
        reachTown('Pending', 3, 4000, null, [], measured: false),
    ];

    $r = GbpReach::compute($towns, 40.85, -74.83, 8.0);

    expect($r['measured'])->toBe(6)
        ->and(collect($r['bands'])->pluck('label')->all())->toBe(['0–5 mi', '5–10 mi', '10–15 mi', '15+ mi'])
        ->and($r['bands'][0])->toMatchArray(['towns' => 2, 'in_pack' => 2, 'share' => 100])
        ->and($r['bands'][1])->toMatchArray(['towns' => 2, 'in_pack' => 1, 'share' => 43])   // 6000 of 14000
        ->and($r['bands'][2])->toMatchArray(['towns' => 1, 'in_pack' => 0, 'share' => 0])
        ->and($r['bands'][3])->toMatchArray(['towns' => 1, 'in_pack' => 0, 'share' => 0])
        // Walking out: Near+Close in (15k/15k), Mid out (15k/23k = 65%), MidIn in (21k/29k = 72%) → reach is MidIn's distance; Far drops it below half.
        ->and($r['reach_miles'])->toBe(9.0)
        // Everyone inside 9 mi (incl. the pending town) over everyone: 33k / 83k.
        ->and($r['addressable'])->toBe(40)
        ->and($r['score'])->toBeGreaterThan(50)->toBeLessThan(90)
        ->and($r['competitors'])->toMatchArray(['lost' => 3, 'closer' => 0, 'farther' => 0, 'unknown' => 3]);
});

it('weights the score by distance: the same ranks score higher when the wins are near and the losses far', function () {
    $winsNear = [reachTown('A', 2, 10000, 1), reachTown('B', 14, 10000, null)];
    $winsFar = [reachTown('A', 2, 10000, null), reachTown('B', 14, 10000, 1)];

    $near = GbpReach::compute($winsNear, 40.85, -74.83, 8.0)['score'];
    $far = GbpReach::compute($winsFar, 40.85, -74.83, 8.0)['score'];

    expect($near)->toBeGreaterThan(70)->and($far)->toBeLessThan(30)
        ->and(GbpReach::compute([], 40.85, -74.83, 8.0)['score'])->toBeNull();
});

it('splits each lost town by whether the pack winner sits closer to it than we do, naming the farther ones', function () {
    $ours = ['lat' => 40.85, 'lng' => -74.83];
    $rival = ['lat' => 40.85, 'lng' => -74.83 + 20 / (69.172 * cos(deg2rad(40.85)))];   // 20 mi east of us
    $towns = [
        reachTown('Nearby', 3, 9000, null, [['name' => 'Rival Pumps', 'place_id' => 'rival', 'rank' => 1]]),     // rival is 17 mi from this town, we are 3 → farther
        reachTown('Theirs', 17, 7000, null, [['name' => 'Rival Pumps', 'place_id' => 'rival', 'rank' => 1]]),    // rival is 3 mi away, we are 17 → closer
        reachTown('Nobody', 6, 2000, null, [['name' => 'Mystery', 'place_id' => 'unknown', 'rank' => 1]]),
        reachTown('Won', 1, 5000, 1, [['name' => 'Rival Pumps', 'place_id' => 'rival', 'rank' => 2]]),
    ];

    $r = GbpReach::compute($towns, $ours['lat'], $ours['lng'], 8.0, ['rival' => [...$rival, 'name' => 'Rival Pumps LLC']]);

    expect($r['competitors'])->toMatchArray(['lost' => 3, 'closer' => 1, 'farther' => 1, 'unknown' => 1])
        ->and($r['competitors']['farther_towns'])->toHaveCount(1)
        ->and($r['competitors']['farther_towns'][0])->toMatchArray(['town' => 'Nearby', 'by' => 'Rival Pumps LLC', 'our_miles' => 3.0])
        ->and($r['competitors']['farther_towns'][0]['their_miles'])->toBe(17.0)
        ->and($r['competitors']['closer_towns'][0]['town'])->toBe('Theirs')
        ->and(GbpReach::competitorIds($towns))->toBe(['rival', 'unknown']);
});

it('the resolver answers known coordinates, queues the unknown ids once, and stores what Places returns', function () {
    Queue::fake();
    CompetitorPlace::create(['place_id' => 'known', 'name' => 'Known Co', 'lat' => 40.9, 'lng' => -74.9, 'resolved_at' => Carbon::now()]);
    CompetitorPlace::create(['place_id' => 'failed-recent', 'failed_at' => Carbon::now()->subDays(2)]);
    CompetitorPlace::create(['place_id' => 'failed-old', 'failed_at' => Carbon::now()->subDays(60)]);
    $resolver = app(CompetitorPlaceResolver::class);

    $coords = $resolver->coords(['known', 'failed-recent', 'failed-old', MockPlacesProvider::PLACE_ID, '']);

    expect(array_keys($coords))->toBe(['known'])->and($coords['known']['name'])->toBe('Known Co');
    Queue::assertPushed(ResolveCompetitorPlaces::class, fn (ResolveCompetitorPlaces $j) => $j->placeIds === ['failed-old', MockPlacesProvider::PLACE_ID]);

    // The worker: the mock knows one id; the other fails and is stamped for a later retry.
    $done = $resolver->resolve([MockPlacesProvider::PLACE_ID, 'failed-old']);
    expect($done)->toBe(['resolved' => 1, 'failed' => 1])
        ->and(CompetitorPlace::find(MockPlacesProvider::PLACE_ID)?->lat)->toBe(30.267153)
        ->and(CompetitorPlace::find('failed-old')?->failed_at?->isToday())->toBeTrue()
        ->and($resolver->coords([MockPlacesProvider::PLACE_ID], false))->toHaveKey(MockPlacesProvider::PLACE_ID);
});
