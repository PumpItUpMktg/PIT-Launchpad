<?php

use App\Enums\FeedOrigin;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Source;

/** A source with explicit health timestamps + creation age. */
function pruneFeed(Site $site, array $attrs): Source
{
    $created = $attrs['created_days_ago'] ?? 60;
    unset($attrs['created_days_ago']);

    $source = Source::factory()->create(array_merge([
        'site_id' => $site->id,
        'origin' => FeedOrigin::Generated->value,
        'url' => 'https://news.google.com/'.fake()->uuid(),
        'enabled' => true,
    ], $attrs));

    $source->forceFill(['created_at' => now()->subDays($created)])->save();

    return $source;
}

it('disables dead (never-produced) and silent feeds, keeps producers and fresh feeds', function () {
    $site = Site::factory()->create();

    $dead = pruneFeed($site, ['last_fetched_at' => now()->subHour(), 'last_item_at' => null, 'created_days_ago' => 60]);
    $silent = pruneFeed($site, ['last_fetched_at' => now()->subHour(), 'last_item_at' => now()->subDays(45), 'created_days_ago' => 90]);
    $producer = pruneFeed($site, ['last_fetched_at' => now()->subHour(), 'last_item_at' => now()->subDay(), 'created_days_ago' => 60]);
    $fresh = pruneFeed($site, ['last_fetched_at' => now()->subHour(), 'last_item_at' => null, 'created_days_ago' => 3]); // within grace
    $neverFetched = pruneFeed($site, ['last_fetched_at' => null, 'last_item_at' => null, 'created_days_ago' => 60]); // never got a chance

    // Report-only: names the counts, changes nothing.
    $this->artisan('launchpad:prune-dead-feeds')
        ->assertSuccessful()
        ->expectsOutputToContain('report-only')
        ->expectsOutputToContain('Would disable 2 feed(s) total — 1 dead, 1 silent.');
    expect($dead->fresh()->enabled)->toBeTrue();

    // --execute disables only the dead + silent.
    $this->artisan('launchpad:prune-dead-feeds --execute')->assertSuccessful();

    expect($dead->fresh()->enabled)->toBeFalse()
        ->and($silent->fresh()->enabled)->toBeFalse()
        ->and($producer->fresh()->enabled)->toBeTrue()
        ->and($fresh->fresh()->enabled)->toBeTrue()          // still within grace
        ->and($neverFetched->fresh()->enabled)->toBeTrue();  // never fetched → not "dead"
});

it('honors --grace-days and --silence-days', function () {
    $site = Site::factory()->create();
    $youngDead = pruneFeed($site, ['last_fetched_at' => now()->subHour(), 'last_item_at' => null, 'created_days_ago' => 5]);

    // Default grace (14d) spares a 5-day-old feed; --grace-days=3 catches it.
    $this->artisan('launchpad:prune-dead-feeds --execute')->assertSuccessful();
    expect($youngDead->fresh()->enabled)->toBeTrue();

    $this->artisan('launchpad:prune-dead-feeds --grace-days=3 --execute')->assertSuccessful();
    expect($youngDead->fresh()->enabled)->toBeFalse();
});

it('reports nothing to prune cleanly', function () {
    $site = Site::factory()->create();
    pruneFeed($site, ['last_fetched_at' => now()->subHour(), 'last_item_at' => now()->subDay(), 'created_days_ago' => 60]);

    $this->artisan('launchpad:prune-dead-feeds')
        ->assertSuccessful()
        ->expectsOutputToContain('Would disable 0 feed(s) total');
});

it('is scoped to --site', function () {
    $mine = Site::factory()->create();
    $other = Site::factory()->create();
    $otherDead = pruneFeed($other, ['last_fetched_at' => now()->subHour(), 'last_item_at' => null, 'created_days_ago' => 60]);

    $this->artisan('launchpad:prune-dead-feeds', ['--site' => $mine->id, '--execute' => true])->assertSuccessful();

    // The other tenant's dead feed is untouched.
    expect(Source::withoutGlobalScope(SiteScope::class)->find($otherDead->id)->enabled)->toBeTrue();
});
