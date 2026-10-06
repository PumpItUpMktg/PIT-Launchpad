<?php

use App\ContentEngine\Review\ReviewActions;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateLocationPages;
use App\Jobs\BoostReleasedPages;
use App\Jobs\PublishContent;
use App\Jobs\ReleasePublishDrip;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Models\User;
use App\Publishing\Drip\PublishDrip;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** A site with the drip on (batch 2), one office, and town pages in every state the drip cares about. */
function dripSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example', 'publish_drip' => ['enabled' => true, 'batch' => 2, 'stale_days' => 21]]);
    $office = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office', 'served_towns' => []]);
    $town = function (string $name, string $geo, int $pop, ContentStatus $status, array $extra = []) use ($site, $office): Content {
        CoverageArea::factory()->create(['site_id' => $site->id, 'name' => $name, 'state' => 'NJ', 'geo_id' => $geo, 'population' => $pop, 'lat' => 40.8, 'lng' => -74.8, 'source_location_ids' => [$office->id]]);

        return Content::factory()->create(array_merge([
            'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => $status,
            'location_id' => null, 'parent_location_id' => $office->id, 'geo_id' => $geo, 'title' => "{$name}, NJ", 'slug' => Str::slug($name).'-nj',
            'slot_payload' => ['hero' => 'x'],
        ], $extra));
    };
    // Live: one indexed, one waiting 3 days (holds a slot), one waiting 40 days (stale — no longer counts).
    $indexed = $town('Washington', '3404177000', 12000, ContentStatus::Published, ['published_at' => now()->subDays(10), 'wp_post_id' => 1]);
    PageIndexState::create(['site_id' => $site->id, 'content_id' => $indexed->id, 'url' => 'https://spg.example/washington-nj/', 'url_normalized' => 'https://spg.example/washington-nj/', 'index_verdict' => 'PASS', 'last_inspected_at' => now(), 'indexed_at' => now()->subDays(5)]);
    $waiting = $town('Independence', '3404133000', 5000, ContentStatus::Published, ['published_at' => now()->subDays(3), 'wp_post_id' => 2]);
    $stale = $town('Allamuchy', '3404100700', 900, ContentStatus::Published, ['published_at' => now()->subDays(40), 'wp_post_id' => 3]);
    // Approved, not yet live: three towns of different sizes.
    $big = $town('Hackettstown', '3404128590', 10000, ContentStatus::Approved);
    $mid = $town('Mansfield', '3404143000', 7000, ContentStatus::Approved);
    $small = $town('Hope', '3404133500', 1900, ContentStatus::Approved);

    return compact('site', 'office', 'indexed', 'waiting', 'stale', 'big', 'mid', 'small');
}

it('queues a first-time publish when the drip is on, pushes a re-push straight through, and publishes past the queue on request', function () {
    Queue::fake();
    $f = dripSite();
    $actions = app(ReviewActions::class);

    $small = $actions->publish($f['small']);
    $big = $actions->publish($f['big']);
    expect($small->isQueued())->toBeTrue()->and($small->queuePosition)->toBe(1)
        ->and($big->isQueued())->toBeTrue()->and($big->queuePosition)->toBe(1);   // bigger town moves ahead
    Queue::assertNotPushed(PublishContent::class);

    // A live page re-publishes immediately — the drip governs first publishes only.
    expect($actions->publish($f['indexed'])->isQueued())->toBeFalse();
    Queue::assertPushed(PublishContent::class, 1);

    // Publish now: past the queue, and off it.
    expect($actions->publish($f['small'], skipDrip: true)->isQueued())->toBeFalse()
        ->and(app(PublishDrip::class)->queued($f['site']))->toHaveCount(1);
    Queue::assertPushed(PublishContent::class, 2);

    // Drip off → straight through.
    app(PublishDrip::class)->configure($f['site'], ['enabled' => false]);
    expect($actions->publish($f['mid'])->isQueued())->toBeFalse();
});

it('releases as many as there are free slots — batch minus the pages still waiting for Google — biggest towns first', function () {
    Queue::fake();
    $f = dripSite();
    $drip = app(PublishDrip::class);
    foreach (['small', 'mid', 'big'] as $k) {
        $drip->enqueue($f[$k]);
    }

    $status = $drip->status($f['site']);
    // Independence (3 days) holds a slot; Washington is indexed and Allamuchy (40 days) is stale — neither counts.
    expect(array_column($status['in_flight'], 'title'))->toBe(['Independence, NJ'])
        ->and($status['slots'])->toBe(1)
        ->and(array_column($status['queued'], 'title'))->toBe(['Hackettstown, NJ', 'Mansfield, NJ', 'Hope, NJ']);

    $released = $drip->release($f['site']);
    expect($released)->toBe([$f['big']->id])
        ->and(array_column($drip->queued($f['site']), 'title'))->toBe(['Mansfield, NJ', 'Hope, NJ']);
    Queue::assertPushed(PublishContent::class, fn (PublishContent $job) => $job->contentId === $f['big']->id);

    // No slots left → the hourly job releases nothing; once Independence is indexed, it releases the next.
    expect($drip->release($f['site']))->toBe([]);
    PageIndexState::create(['site_id' => $f['site']->id, 'content_id' => $f['waiting']->id, 'url' => 'https://spg.example/independence-nj/', 'url_normalized' => 'https://spg.example/independence-nj/', 'index_verdict' => 'PASS', 'last_inspected_at' => now(), 'indexed_at' => now()]);
    app()->call([new ReleasePublishDrip, 'handle']);
    expect(array_column($drip->queued($f['site']), 'title'))->toBe(['Hope, NJ']);

    $this->artisan('launchpad:publish-drip', ['--site' => 'SPG'])
        ->expectsOutputToContain('Publish drip — SPG: ON · batch 2')
        ->expectsOutputToContain('queued: 1')
        ->assertSuccessful();
    $this->artisan('launchpad:publish-drip', ['--site' => 'SPG', '--off' => true, '--batch' => 5])
        ->expectsOutputToContain('Publish drip — SPG: off · batch 5')
        ->assertSuccessful();
});

it('the pages board shows the drip panel, queues from its Publish button, and offers Publish now on a queued row', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $f = dripSite();
    session(['guided_site_id' => $f['site']->id]);

    $board = Livewire::test(OperateLocationPages::class)
        ->set('siteId', $f['site']->id)
        ->call('setLocTab', $f['office']->id)
        ->assertOk()
        ->assertSee('Publish drip')
        ->assertSeeHtml('<b>1</b> waiting for Google')
        ->assertSee('Queue to publish')
        ->call('publish', $f['big']->id)
        ->assertSee('Queued #1')
        ->assertSeeHtml('wire:click="publishNow(\''.$f['big']->id.'\')"');
    Queue::assertNotPushed(PublishContent::class);

    $board->call('publishNow', $f['big']->id);
    Queue::assertPushed(PublishContent::class, 1);

    $board->call('toggleDrip');
    expect($f['site']->fresh()->publishDrip()['enabled'])->toBeFalse();
});

it('a release queues the inbound-link boost for exactly the released pages, after the pushes have landed', function () {
    Queue::fake();
    $f = dripSite();
    $drip = app(PublishDrip::class);
    $drip->enqueue($f['big']);

    $released = $drip->release($f['site']);
    expect($released)->toBe([$f['big']->id]);
    Queue::assertPushed(BoostReleasedPages::class, fn (BoostReleasedPages $job) => $job->siteId === $f['site']->id && $job->contentIds === [$f['big']->id] && $job->delay !== null);

    // When the job runs, the now-live page is linked from the indexed office-sibling and that source re-pushed.
    $f['big']->forceFill(['wp_post_id' => 55, 'published_at' => now()])->save();
    $f['indexed']->forceFill(['slot_payload' => ['intro' => 'Washington homes get the same crew and the same guarantee.']])->save();
    app()->call([new BoostReleasedPages($f['site']->id, [$f['big']->id]), 'handle']);
    expect($f['indexed']->fresh()->slot_payload['intro'])->toContain('href="/hackettstown-nj"');
});
