<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\SocialNetwork;
use App\Enums\SocialPostStatus;
use App\Enums\SocialSuggestionStatus;
use App\Enums\SocialTrigger;
use App\Jobs\SendSocialPost;
use App\Metrics\Providers\WeatherMetricProvider;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\Location;
use App\Models\MetricSnapshot;
use App\Models\Site;
use App\Models\SocialChannel;
use App\Models\SocialPost;
use App\Models\SocialSuggestion;
use App\Social\Rules\MomentumRule;
use App\Social\Rules\RainPool;
use App\Social\Rules\WeatherRule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function rulesSite(): Site
{
    return Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
}

function rulesPost(Site $site, string $slug, string $title, string $body = '<p>Body.</p>'): Content
{
    return Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published, 'title' => $title, 'slug' => $slug,
        'body' => $body, 'wp_post_id' => 4, 'published_at' => now()->subDays(60),
    ]);
}

function rulesGsc(Site $site, string $path, int $daysAgo, int $impressions, int $clicks, ?float $position): void
{
    GscUrlDaily::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'grain_hash' => Str::random(40),
        'date' => now()->subDays($daysAgo)->toDateString(), 'url' => 'https://spg.example'.$path,
        'impressions' => $impressions, 'clicks' => $clicks, 'position' => $position,
    ]);
}

function rulesRain(Site $site, int $daysAgo, float $inches, string $station = 'GHCND:USW1'): void
{
    MetricSnapshot::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'provider' => WeatherMetricProvider::PROVIDER, 'metric_key' => WeatherMetricProvider::METRIC,
        'dimension_type' => 'station', 'dimension_value' => $station, 'period_grain' => 'day',
        'period_date' => now()->subDays($daysAgo)->toDateString(), 'value_numeric' => $inches, 'value_json' => ['station' => 'Newark'], 'captured_at' => now(),
    ]);
}

it('upward movement: impressions doubling, a position gain inside the top 20, or first clicks each put the post on the board with the numbers', function () {
    $site = rulesSite();
    SocialChannel::withoutGlobalScopes()->create(['site_id' => $site->id, 'network' => SocialNetwork::Facebook, 'provider_channel_id' => 'fb']);
    $doubling = rulesPost($site, 'doubling', 'Doubling');
    $climbing = rulesPost($site, 'climbing', 'Climbing');
    $clicked = rulesPost($site, 'clicked', 'Clicked');
    $flat = rulesPost($site, 'flat', 'Flat');
    // Prior week (days 8–14) vs trailing week (days 1–7); the latest synced day is day 1.
    foreach ([1, 3, 5] as $d) {
        rulesGsc($site, '/doubling/', $d, 20, 0, 15.0);
        rulesGsc($site, '/climbing/', $d, 10, 0, 9.0);
        rulesGsc($site, '/clicked/', $d, 5, 2, 30.0);
        rulesGsc($site, '/flat/', $d, 10, 1, 12.0);
    }
    foreach ([8, 10, 12] as $d) {
        rulesGsc($site, '/doubling/', $d, 8, 0, 15.0);
        rulesGsc($site, '/climbing/', $d, 10, 0, 14.0);
        rulesGsc($site, '/clicked/', $d, 5, 0, 30.0);
        rulesGsc($site, '/flat/', $d, 10, 1, 12.0);
    }

    $hits = app(MomentumRule::class)->run($site);

    $byTitle = collect($hits)->keyBy('title');
    expect($byTitle->keys()->sort()->values()->all())->toBe(['Clicked', 'Climbing', 'Doubling'])
        ->and($byTitle['Doubling']['reason'])->toBe('Impressions 24 → 60 this week')
        ->and($byTitle['Doubling']['snapshot']['rule'])->toBe('impressions')
        ->and($byTitle['Climbing']['reason'])->toBe('Position 14 → 9')
        ->and($byTitle['Clicked']['reason'])->toBe('First clicks: 6 this week')
        ->and(SocialSuggestion::withoutGlobalScopes()->where('trigger', 'momentum')->count())->toBe(3)
        ->and(SocialSuggestion::withoutGlobalScopes()->where('content_id', $flat->id)->exists())->toBeFalse();

    // Report mode writes nothing more; a second execute run does not duplicate the open suggestions.
    app(MomentumRule::class)->run($site, false);
    app(MomentumRule::class)->run($site);
    expect(SocialSuggestion::withoutGlobalScopes()->count())->toBe(3);
});

it('the rain pool holds the storm-vocabulary posts (pins honoured) and the draw never repeats a post shared to the same channel inside the cooldown', function () {
    $site = rulesSite();
    $fb = SocialChannel::withoutGlobalScopes()->create(['site_id' => $site->id, 'network' => SocialNetwork::Facebook, 'provider_channel_id' => 'fb']);
    $a = rulesPost($site, 'a', 'Why your sump pump runs constantly');
    $b = rulesPost($site, 'b', 'Battery backup options', '<p>When the power goes out in a storm.</p>');
    $c = rulesPost($site, 'c', 'Kitchen remodel ideas');
    $pinnedIn = rulesPost($site, 'd', 'Spring checklist');
    $pinnedIn->forceFill(['meta' => ['social' => ['weather' => true]]])->save();
    $pinnedOut = rulesPost($site, 'e', 'Flood insurance explained');
    $pinnedOut->forceFill(['meta' => ['social' => ['weather' => false]]])->save();
    $pool = app(RainPool::class);

    $ids = $pool->pool($site)->pluck('id')->sort()->values()->all();
    expect($ids)->toBe(collect([$a->id, $b->id, $pinnedIn->id])->sort()->values()->all());

    // a shared to Facebook yesterday → out of Facebook's draw; the others stay in.
    SocialPost::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'social_channel_id' => $fb->id, 'subject_type' => 'post', 'subject_id' => $a->id,
        'network' => SocialNetwork::Facebook, 'trigger' => SocialTrigger::Weather, 'status' => SocialPostStatus::Sent, 'copy' => 'x', 'created_at' => now()->subDay(),
    ]);
    $seen = [];
    foreach (range(1, 40) as $seed) {
        $drawn = $pool->draw($pool->pool($site), [(string) $fb->id], 30, $seed);
        $seen[(string) $drawn['post']->id] = true;
    }
    expect(array_keys($seen))->not->toContain($a->id)->and(count($seen))->toBe(2);

    // Everything in cooldown → nothing to draw.
    foreach ([$b, $pinnedIn] as $p) {
        SocialPost::withoutGlobalScopes()->create([
            'site_id' => $site->id, 'social_channel_id' => $fb->id, 'subject_type' => 'post', 'subject_id' => $p->id,
            'network' => SocialNetwork::Facebook, 'trigger' => SocialTrigger::Weather, 'status' => SocialPostStatus::Sent, 'copy' => 'x', 'created_at' => now()->subDay(),
        ]);
    }
    expect($pool->draw($pool->pool($site), [(string) $fb->id], 30, 1))->toBeNull();
});

it('heavy rain sends on its own — one draw per GBP location and one for the Pages, a storm line first, recorded as auto rows — and fires once per rain event', function () {
    Queue::fake();
    $site = rulesSite();
    $north = Location::factory()->create(['site_id' => $site->id, 'name' => 'North']);
    $south = Location::factory()->create(['site_id' => $site->id, 'name' => 'South']);
    SocialChannel::withoutGlobalScopes()->create(['site_id' => $site->id, 'network' => SocialNetwork::Gbp, 'provider_channel_id' => 'g1', 'location_id' => $north->id]);
    SocialChannel::withoutGlobalScopes()->create(['site_id' => $site->id, 'network' => SocialNetwork::Gbp, 'provider_channel_id' => 'g2', 'location_id' => $south->id]);
    SocialChannel::withoutGlobalScopes()->create(['site_id' => $site->id, 'network' => SocialNetwork::Facebook, 'provider_channel_id' => 'fb']);
    rulesPost($site, 'a', 'Why your sump pump runs constantly');
    rulesPost($site, 'b', 'Battery backup for a storm');
    rulesPost($site, 'c', 'Kitchen remodel ideas');
    rulesRain($site, 1, 0.3);

    // Below threshold: nothing.
    $dry = app(WeatherRule::class)->run($site);
    expect($dry['fired'])->toBeFalse()->and($dry['shares'])->toBe([]);

    // 1.4 in. yesterday → fires: North, South, and the Page each get a draw.
    rulesRain($site, 0, 1.4);
    $wet = app(WeatherRule::class)->run($site);
    expect($wet['fired'])->toBeTrue()->and($wet['inches_1d'])->toBe(1.4)->and($wet['shares'])->toHaveCount(3)
        ->and(collect($wet['shares'])->pluck('location')->all())->toBe(['North', 'South', null]);
    $rows = SocialPost::withoutGlobalScopes()->where('trigger', 'weather')->get();
    expect($rows)->toHaveCount(3)
        ->and($rows->every(fn (SocialPost $p): bool => $p->social_suggestion_id !== null && str_contains($p->copy, $p->snapshot['lead'])))->toBeTrue()
        ->and($rows->every(fn (SocialPost $p): bool => ! str_contains($p->copy, 'Kitchen')))->toBeTrue()
        ->and(SocialSuggestion::withoutGlobalScopes()->where('status', SocialSuggestionStatus::Auto->value)->count())->toBe(3);
    Queue::assertPushed(SendSocialPost::class, 3);

    // Still raining tomorrow: the same event, no second share.
    rulesRain($site, 0, 2.0, 'GHCND:USW2');
    $again = app(WeatherRule::class)->run($site);
    expect($again['fired'])->toBeTrue()->and($again['skipped'])->toBe('already shared for this rain event')->and(SocialPost::withoutGlobalScopes()->count())->toBe(3);
});

it('the social-rules command reports, and --execute writes', function () {
    $site = rulesSite();
    SocialChannel::withoutGlobalScopes()->create(['site_id' => $site->id, 'network' => SocialNetwork::Facebook, 'provider_channel_id' => 'fb']);
    rulesPost($site, 'doubling', 'Doubling');
    foreach ([1, 3, 5] as $d) {
        rulesGsc($site, '/doubling/', $d, 20, 0, 15.0);
    }
    foreach ([8, 10] as $d) {
        rulesGsc($site, '/doubling/', $d, 8, 0, 15.0);
    }

    Artisan::call('launchpad:social-rules', ['site' => $site->id]);
    expect(Artisan::output())->toContain('Upward movement: 1 post(s)')->toContain('Doubling — Impressions 16 → 60 this week')->toContain('no rainfall series')->toContain('Report only')
        ->and(SocialSuggestion::withoutGlobalScopes()->count())->toBe(0);

    Artisan::call('launchpad:social-rules', ['site' => $site->id, '--execute' => true]);
    expect(SocialSuggestion::withoutGlobalScopes()->count())->toBe(1);
});
