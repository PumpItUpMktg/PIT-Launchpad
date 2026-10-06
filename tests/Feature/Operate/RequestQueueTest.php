<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\IndexingBoard;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Operator\Coverage\RequestQueue;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/** Pages Google has not crawled, in every shape the day's list has to order. */
function requestSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'RQ', 'domain_url' => 'https://rq.example', 'gsc_property' => 'sc-domain:rq.example']);
    $office = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office', 'served_towns' => []]);
    $verdict = function (Content $page, string $state) use ($site): void {
        PageIndexState::create(['site_id' => $site->id, 'content_id' => $page->id, 'url' => "https://rq.example/{$page->slug}/", 'url_normalized' => "https://rq.example/{$page->slug}", 'coverage_state' => $state, 'index_verdict' => $state, 'last_inspected_at' => now()->subDay()]);
    };
    $page = fn (array $attrs) => Content::factory()->create(array_merge(['site_id' => $site->id, 'kind' => ContentKind::Page, 'status' => ContentStatus::Published, 'wp_post_id' => 1], $attrs));
    $service = $page(['page_type' => PageType::Service, 'title' => 'Sump Pump Repair', 'slug' => 'sump-pump-repair', 'published_at' => now()->subDays(20)]);
    $town = function (string $name, string $geo, int $pop, int $days) use ($page, $office, $site): Content {
        CoverageArea::factory()->create(['site_id' => $site->id, 'name' => $name, 'state' => 'NJ', 'geo_id' => $geo, 'population' => $pop, 'lat' => 40.8, 'lng' => -74.8, 'source_location_ids' => [$office->id]]);

        return $page(['page_type' => PageType::Location, 'location_id' => null, 'parent_location_id' => $office->id, 'geo_id' => $geo, 'title' => "{$name}, NJ", 'slug' => strtolower($name).'-nj', 'published_at' => now()->subDays($days)]);
    };
    $big = $town('Washington', '3404177000', 12000, 16);
    $small = $town('Allamuchy', '3404100700', 900, 40);
    $young = $town('Hope', '3404133500', 1900, 5);          // under after_days: not yet
    $post = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'title' => 'Where Sump Water Goes', 'slug' => 'where-water-goes', 'wp_post_id' => 2, 'published_at' => now()->subDays(45)]);
    $crawled = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'title' => 'Crawled And Declined', 'slug' => 'crawled', 'wp_post_id' => 3, 'published_at' => now()->subDays(45)]);
    foreach ([$service, $big, $small, $young, $post] as $p) {
        $verdict($p, IndexCoverageState::DiscoveredNotIndexed->value);
    }
    $verdict($crawled, IndexCoverageState::CrawledNotIndexed->value);   // the rework window, never the request list

    return compact('site', 'service', 'big', 'small', 'young', 'post', 'crawled');
}

it('lists the day\'s requests in value order — service pages, then towns biggest first, then posts — never a crawled page or one too young', function () {
    $f = requestSite();

    $q = app(RequestQueue::class)->for($f['site']);

    expect(array_column($q['rows'], 'title'))->toBe(['Sump Pump Repair', 'Washington, NJ', 'Allamuchy, NJ', 'Where Sump Water Goes'])
        ->and($q['eligible'])->toBe(4)->and($q['limit'])->toBe(10)->and($q['requested_today'])->toBe(0)
        ->and($q['rows'][1]['kind'])->toBe('town')->and($q['rows'][1]['population'])->toBe(12000)
        ->and($q['rows'][0]['inspect_url'])->toBe('https://search.google.com/search-console/inspect?resource_id=sc-domain%3Arq.example&id=https%3A%2F%2Frq.example%2Fsump-pump-repair%2F');

    // A smaller limit takes the top of the same order.
    expect(array_column(app(RequestQueue::class)->for($f['site'], 2)['rows'], 'title'))->toBe(['Sump Pump Repair', 'Washington, NJ']);
});

it('"Requested" stamps the page off the list for the cooldown, counts it for today, and comes back after the cooldown if still not indexed', function () {
    $f = requestSite();
    $queue = app(RequestQueue::class);

    $queue->markRequested($f['big']);
    $q = $queue->for($f['site']);
    expect(array_column($q['rows'], 'title'))->not->toContain('Washington, NJ')
        ->and($q['requested_today'])->toBe(1)
        ->and(RequestQueue::requestedAt($f['big']->fresh()))->not->toBeNull();

    Carbon::setTestNow(now()->addDays(15));
    $q = $queue->for($f['site']);
    expect(array_column($q['rows'], 'title'))->toContain('Washington, NJ')
        ->and(collect($q['rows'])->firstWhere('title', 'Washington, NJ')['requests'])->toBe(1);
    Carbon::setTestNow();

    $queue->unmark($f['big']->fresh());
    expect(RequestQueue::requestedAt($f['big']->fresh()))->toBeNull();
});

it('the board shows Request today with the inspect links and stamps a page from it; the Why? panel shows the request date', function () {
    config()->set('launchpad.indexing.stuck_days', 10);
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $f = requestSite();
    app(ActiveTenant::class)->set($f['site']->id);

    $test = Livewire::test(IndexingBoard::class)
        ->assertSee('Request today')
        ->assertSee('Sump Pump Repair')
        ->assertSeeHtml('search.google.com/search-console/inspect?resource_id=sc-domain%3Arq.example')
        ->call('markRequested', $f['service']->id);

    expect(RequestQueue::requestedAt($f['service']->fresh()))->not->toBeNull();

    $html = $test->call('explain', $f['service']->id)->html();
    expect($html)->toContain('Requested in Search Console: <b>'.now()->format('j M').'</b>');
});
