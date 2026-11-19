<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\SocialNetwork;
use App\Enums\SocialPostStatus;
use App\Enums\SocialSuggestionStatus;
use App\Enums\SocialTrigger;
use App\Jobs\SendSocialPost;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\SocialChannel;
use App\Models\SocialPost;
use App\Models\SocialSuggestion;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\Social\Provider\MockSocialProvider;
use App\Social\Provider\SocialProvider;
use App\Social\SharePlanner;
use App\Social\SocialSharer;
use App\Social\SpotlightTowns;
use App\TownRank\TownRankPoints;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * A site with two GBP offices and a Page. Trooper serves two towns: Phoenixville (published page, ranked 8 —
 * striking distance) and Oaks (no page). Montclair serves no coverage town at all.
 *
 * @return array{site: Site, trooper: Location, montclair: Location, trooperGbp: SocialChannel, montclairGbp: SocialChannel, fb: SocialChannel, posts: list<Content>}
 */
function plannerSite(int $posts = 3): array
{
    Carbon::setTestNow('2026-11-19 10:00:00');
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://spg.example']);
    $trooper = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper office', 'lat' => 40.15, 'lng' => -75.40]);
    $montclair = Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair office', 'lat' => 40.82, 'lng' => -74.21]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Phoenixville', 'geo_id' => '4259816', 'state' => 'PA', 'population' => 18000, 'lat' => 40.13, 'lng' => -75.51, 'source_location_ids' => [$trooper->id]]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Oaks', 'geo_id' => '4255416', 'state' => 'PA', 'population' => 3000, 'lat' => 40.13, 'lng' => -75.45, 'source_location_ids' => [$trooper->id]]);
    Content::factory()->page()->published()->create(['site_id' => $site->id, 'page_type' => PageType::Location, 'status' => ContentStatus::Published, 'geo_id' => '4259816', 'slug' => 'phoenixville-pa']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair']);
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'complete', 'points_count' => 1, 'found_count' => 1, 'scanned_at' => now()->subDay()]);
    TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'label' => 'Phoenixville', 'geo_id' => '4259816', 'lat' => 40.13, 'lng' => -75.51, 'query' => 'sump pump repair', 'rank' => 8, 'collected_at' => now()->subDay()]);

    $fb = SocialChannel::withoutGlobalScopes()->create(['site_id' => $site->id, 'network' => SocialNetwork::Facebook, 'provider_channel_id' => 'fb', 'provider_name' => 'SPG Corporate']);
    $trooperGbp = SocialChannel::withoutGlobalScopes()->create(['site_id' => $site->id, 'network' => SocialNetwork::Gbp, 'provider_channel_id' => 'g-trooper', 'provider_name' => 'Sump Pump Gurus | Trooper', 'location_id' => $trooper->id]);
    $montclairGbp = SocialChannel::withoutGlobalScopes()->create(['site_id' => $site->id, 'network' => SocialNetwork::Gbp, 'provider_channel_id' => 'g-montclair', 'provider_name' => 'Sump Pump Gurus', 'location_id' => $montclair->id]);

    $made = [];
    for ($i = 0; $i < $posts; $i++) {
        $made[] = Content::factory()->create([
            'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published, 'title' => "Post {$i}", 'slug' => "post-{$i}", 'body' => '<p>Body text about pumps.</p>', 'wp_post_id' => 10 + $i,
            'published_at' => now()->subDays(30 + $i), 'meta' => ['seo' => ['meta_description' => "Why pumps fail, part {$i}."]],
        ]);
    }
    app(TownRankPoints::class)->forget();

    return ['site' => $site, 'trooper' => $trooper, 'montclair' => $montclair, 'trooperGbp' => $trooperGbp, 'montclairGbp' => $montclairGbp, 'fb' => $fb, 'posts' => $made];
}

afterEach(fn () => Carbon::setTestNow());

it('picks the spotlight town from the towns the office serves — striking distance weighted, a town with a page preferred, a town spotlighted inside the window skipped', function () {
    $f = plannerSite(0);
    $spot = app(SpotlightTowns::class);

    $candidates = $spot->candidates($f['site'], $f['trooper']);
    expect(collect($candidates)->pluck('town')->sort()->values()->all())->toBe(['Oaks', 'Phoenixville'])
        ->and(collect($candidates)->firstWhere('town', 'Phoenixville'))->toMatchArray(['rank' => 8, 'weight' => 4, 'page_url' => 'https://spg.example/phoenixville-pa'])
        ->and(collect($candidates)->firstWhere('town', 'Oaks'))->toMatchArray(['rank' => null, 'weight' => 1, 'page_url' => null]);

    // Phoenixville has the page: it is the pool. Montclair serves no coverage town: nothing to spotlight.
    expect($spot->pick($f['site'], $f['trooper'], $f['trooperGbp'])['town'])->toBe('Phoenixville')
        ->and($spot->pick($f['site'], $f['montclair'], $f['montclairGbp']))->toBeNull();

    // Spotlighted on this channel a week ago → Oaks is what is left.
    SocialPost::withoutGlobalScopes()->create(['site_id' => $f['site']->id, 'social_channel_id' => $f['trooperGbp']->id, 'subject_type' => 'post', 'subject_id' => 'x', 'network' => SocialNetwork::Gbp, 'trigger' => SocialTrigger::Planned, 'status' => SocialPostStatus::Sent, 'copy' => 'c', 'snapshot' => ['spotlight' => ['area_id' => (string) CoverageArea::withoutGlobalScopes()->where('name', 'Phoenixville')->value('id')]], 'created_at' => now()->subDays(7)]);
    expect($spot->pick($f['site'], $f['trooper'], $f['trooperGbp'])['town'])->toBe('Oaks');

    expect(SpotlightTowns::weight(2))->toBe(1)->and(SpotlightTowns::weight(9))->toBe(4)->and(SpotlightTowns::weight(22))->toBe(2)->and(SpotlightTowns::weight(null))->toBe(1);
});

it('plans one post per cadence slot with the Page copy and a per-office GBP copy: Trooper leads with its spotlight town and links its page, Montclair links the post', function () {
    $f = plannerSite(3);

    $plan = app(SharePlanner::class)->plan($f['site'], ['days' => 6, 'cadence' => 'alternate', 'start' => '2026-11-20', 'networks' => ['facebook', 'gbp'], 'gbp_link' => 'town']);

    expect($plan['slots'])->toBe(3)->and($plan['unfilled'])->toBe(0)->and($plan['items'])->toHaveCount(3)
        ->and(array_column($plan['items'], 'date'))->toBe(['2026-11-20', '2026-11-22', '2026-11-24'])
        // never shared → oldest published first
        ->and(array_column($plan['items'], 'title'))->toBe(['Post 2', 'Post 1', 'Post 0']);
    $first = $plan['items'][0];
    expect($first['copy']['facebook'])->toContain('Post 2')->toContain('Read the answer: https://spg.example/post-2/')
        ->and($first['offices'])->toHaveCount(2);
    $trooper = collect($first['offices'])->firstWhere('town', 'Trooper');
    $montclair = collect($first['offices'])->firstWhere('town', 'Montclair');
    expect($trooper['spotlight']['town'])->toBe('Phoenixville')
        ->and($trooper['lead'])->toContain('Phoenixville')
        ->and($trooper['link'])->toStartWith('https://spg.example/phoenixville-pa?utm_source=gbp')
        ->and($trooper['copy'])->toStartWith($trooper['lead'])
        ->and($trooper['copy'])->not->toContain('https://')   // GBP: the link is the button, never in the text
        ->and($montclair['spotlight'])->toBeNull()
        ->and($montclair['lead'])->toBeNull()
        ->and($montclair['link'])->toStartWith('https://spg.example/post-2/?utm_source=gbp');

    // Pick the posts by hand, weekly, link GBP to the post: the order is the pick's, no spotlight link.
    $hand = app(SharePlanner::class)->plan($f['site'], ['days' => 14, 'cadence' => 'weekly', 'start' => '2026-11-20', 'networks' => ['gbp'], 'post_ids' => [$f['posts'][0]->id, $f['posts'][2]->id], 'gbp_link' => 'post', 'location_ids' => [$f['trooper']->id]]);
    expect(array_column($hand['items'], 'title'))->toBe(['Post 0', 'Post 2'])
        ->and(array_column($hand['items'], 'date'))->toBe(['2026-11-20', '2026-11-27'])
        ->and($hand['items'][0]['offices'])->toHaveCount(1)
        ->and($hand['items'][0]['offices'][0]['link'])->toStartWith('https://spg.example/post-0/?utm_source=gbp')
        ->and($hand['items'][0]['offices'][0]['lead'])->toContain('Phoenixville')
        ->and($hand['items'][0]['copy'])->toBe([]);

    // Too few posts: the slots stay unfilled, nothing repeats.
    $thin = app(SharePlanner::class)->plan($f['site'], ['days' => 30, 'cadence' => 'daily', 'networks' => ['facebook']]);
    expect($thin['slots'])->toBe(30)->and($thin['items'])->toHaveCount(3)->and($thin['unfilled'])->toBe(27);
});

it('commits a plan: an approved Planned suggestion per item, shares queued for the planned day with the edited copy, the spotlight on the GBP share; the calendar lists, reschedules and cancels them', function () {
    Queue::fake();
    $f = plannerSite(2);
    $planner = app(SharePlanner::class);
    $plan = $planner->plan($f['site'], ['days' => 4, 'cadence' => 'alternate', 'start' => '2026-11-20', 'networks' => ['facebook', 'gbp'], 'location_ids' => [$f['trooper']->id]]);
    $trooperKey = 'gbp:'.$f['trooperGbp']->id;

    $r = $planner->commit($f['site'], $plan['items'], [0 => ['facebook' => 'My own words for the Page.', $trooperKey => 'My own GBP words, call 908-555-1212 today.']]);

    expect($r)->toBe(['suggestions' => 2, 'shares' => 4]);
    $suggestions = SocialSuggestion::withoutGlobalScopes()->orderBy('created_at')->get();
    expect($suggestions)->toHaveCount(2)
        ->and($suggestions[0]->trigger)->toBe(SocialTrigger::Planned)
        ->and($suggestions[0]->status)->toBe(SocialSuggestionStatus::Approved)
        ->and($suggestions[0]->location_ids)->toBe([$f['trooper']->id])
        ->and($suggestions[0]->snapshot['spotlights'][(string) $f['trooperGbp']->id]['town'])->toBe('Phoenixville');

    $rows = SocialPost::withoutGlobalScopes()->orderBy('scheduled_for')->orderBy('network')->get();
    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('status')->unique()->all())->toBe([SocialPostStatus::Queued])
        ->and($rows->map(fn (SocialPost $p): string => $p->scheduled_for->toDateString())->all())->toBe(['2026-11-20', '2026-11-20', '2026-11-22', '2026-11-22']);
    $fbFirst = $rows->first(fn (SocialPost $p): bool => $p->network === SocialNetwork::Facebook);
    $gbpFirst = $rows->first(fn (SocialPost $p): bool => $p->network === SocialNetwork::Gbp);
    expect($fbFirst->copy)->toBe('My own words for the Page.')
        ->and($gbpFirst->copy)->toBe('My own GBP words, call today.')   // the phone number never reaches GBP
        ->and($gbpFirst->snapshot['spotlight']['town'])->toBe('Phoenixville')
        ->and($gbpFirst->link)->toStartWith('https://spg.example/phoenixville-pa?utm_source=gbp')
        ->and($gbpFirst->scheduled_for->hour)->toBe((int) config('launchpad.social.slot_hour'));
    Queue::assertPushed(SendSocialPost::class, 4);

    // The calendar.
    $days = $planner->upcoming($f['site']->id);
    expect(array_column($days, 'date'))->toBe(['2026-11-20', '2026-11-22'])
        ->and($days[0]['posts'])->toHaveCount(2)
        ->and(collect($days[0]['posts'])->firstWhere('network', 'gbp'))->toMatchArray(['office' => 'Trooper', 'spotlight' => 'Phoenixville', 'trigger' => 'planned']);

    // Reschedule keeps the hour. The job already queued for the old time wakes, sees the future time and re-queues
    // itself instead of sending (the unique lock from the first dispatch is released before processing; the fake
    // never releases it — and an array-store lock survives a flush — so release it here to see the re-queue).
    expect($planner->reschedule($gbpFirst, Carbon::parse('2026-11-25')))->toBeTrue()
        ->and($gbpFirst->fresh()->scheduled_for->toDateTimeString())->toBe('2026-11-25 '.sprintf('%02d', (int) config('launchpad.social.slot_hour')).':00:00');
    (new UniqueLock(Cache::store()))->release(new SendSocialPost($gbpFirst->id));
    (new SendSocialPost($gbpFirst->id))->handle(app(SocialSharer::class));
    /** @var MockSocialProvider $mock */
    $mock = app(SocialProvider::class);
    expect($mock->published)->toBe([])->and($gbpFirst->fresh()->status)->toBe(SocialPostStatus::Queued);
    Queue::assertPushed(SendSocialPost::class, 5);

    // Cancel takes it off; a rewrite changes the text; neither touches a share that already went.
    expect($planner->cancel($fbFirst))->toBeTrue()->and($fbFirst->fresh()->status)->toBe(SocialPostStatus::Skipped)
        ->and($planner->rewrite($gbpFirst->fresh(), 'New text'))->toBeTrue()->and($gbpFirst->fresh()->copy)->toBe('New text')
        ->and($planner->cancel($fbFirst->fresh()))->toBeFalse();
    expect(array_column($planner->upcoming($f['site']->id), 'date'))->toBe(['2026-11-22', '2026-11-25']);
});

it('a planned share is a real share: it counts for the cooldown and the next auto-fill skips that post', function () {
    Queue::fake();
    $f = plannerSite(2);
    $planner = app(SharePlanner::class);
    $planner->commit($f['site'], $planner->plan($f['site'], ['days' => 2, 'cadence' => 'daily', 'start' => '2026-11-20', 'networks' => ['facebook'], 'post_ids' => [$f['posts'][0]->id]])['items']);

    $next = $planner->plan($f['site'], ['days' => 10, 'cadence' => 'daily', 'networks' => ['facebook']]);
    expect(array_column($next['items'], 'title'))->toBe(['Post 1']);
});
