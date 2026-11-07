<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\SocialNetwork;
use App\Enums\SocialPostStatus;
use App\Enums\SocialSuggestionStatus;
use App\Enums\SocialTrigger;
use App\Jobs\SendSocialPost;
use App\Jobs\SuggestPostShare;
use App\Models\Content;
use App\Models\ContentTown;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use App\Models\SocialChannel;
use App\Models\SocialPost;
use App\Models\SocialSuggestion;
use App\Social\SuggestionBoard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

function boardSite(): Site
{
    return Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
}

function boardChannel(Site $site, SocialNetwork $network, ?Location $location = null): SocialChannel
{
    return SocialChannel::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'location_id' => $location?->id, 'network' => $network, 'provider_channel_id' => $network->value.'-'.($location?->id ?? 'site'),
    ]);
}

function boardPost(Site $site, string $slug = 'cost-post', array $meta = []): Content
{
    return Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published, 'title' => 'Cost Post '.$slug, 'slug' => $slug,
        'body' => '<p>Body.</p>', 'wp_post_id' => 4, 'published_at' => now()->subDays(30), 'meta' => $meta,
    ])->fresh(['site', 'silo', 'renderJobs']);
}

it('suggests once per post with the site\'s networks pre-ticked and the GBP locations resolved from the post; approve queues the ticked platforms only; skip closes it', function () {
    Queue::fake();
    $site = boardSite();
    $north = Location::factory()->create(['site_id' => $site->id, 'name' => 'North']);
    $south = Location::factory()->create(['site_id' => $site->id, 'name' => 'South']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown', 'source_location_ids' => [$north->id]]);
    $northGbp = boardChannel($site, SocialNetwork::Gbp, $north);
    boardChannel($site, SocialNetwork::Gbp, $south);
    $fb = boardChannel($site, SocialNetwork::Facebook);
    boardChannel($site, SocialNetwork::Instagram);
    $post = boardPost($site);
    ContentTown::create(['content_id' => $post->id, 'site_id' => $site->id, 'town' => 'hackettstown', 'town_display' => 'Hackettstown']);
    $board = app(SuggestionBoard::class);

    $s = $board->suggest($post, SocialTrigger::PostRefreshed, 'Refreshed to answer "how much"', ['question' => 'how much']);
    expect($s)->not->toBeNull()
        ->and($s->status)->toBe(SocialSuggestionStatus::Open)
        ->and($s->networks)->toBe(['facebook', 'gbp', 'instagram'])
        ->and($s->location_ids)->toBe([$north->id])
        ->and($board->suggest($post, SocialTrigger::Momentum, 'again'))->toBeNull();   // one open suggestion per post

    $open = $board->open($site->id);
    expect($open)->toHaveCount(1)->and($open[0]['gbp_locations'])->toBe(['North'])->and($open[0]['copy'])->toContain('Cost Post');

    $rows = $board->approve($s, ['gbp', 'facebook']);
    expect(collect($rows)->pluck('social_channel_id')->sort()->values()->all())->toBe(collect([$northGbp->id, $fb->id])->sort()->values()->all())
        ->and($rows[0]->social_suggestion_id)->toBe($s->id)
        ->and($s->fresh()->status)->toBe(SocialSuggestionStatus::Approved)
        ->and($s->fresh()->networks)->toBe(['gbp', 'facebook']);
    Queue::assertPushed(SendSocialPost::class, 2);

    // Inside the cooldown the post is not proposed again by a rule — a manual pick still is.
    expect($board->suggest($post, SocialTrigger::Momentum, 'moved'))->toBeNull()
        ->and($board->suggest($post, SocialTrigger::Manual, 'picked'))->not->toBeNull();
    $manual = SocialSuggestion::withoutGlobalScopes()->where('status', 'open')->firstOrFail();
    $board->skip($manual);
    expect($manual->fresh()->status)->toBe(SocialSuggestionStatus::Skipped)
        ->and($board->decided($site->id))->toHaveCount(2);
});

it('the suggest job carries the refresh snapshot, and a site with no channels gets no suggestion', function () {
    $site = boardSite();
    $post = boardPost($site, 'q-post', ['question_refresh' => ['question' => 'why pumps run', 'answer' => 'floats', 'also' => [['question' => 'x'], ['question' => 'y']], 'refreshed_at' => now()->toIso8601String()]]);
    (new SuggestPostShare($post->id, 'post_refreshed', 'Refreshed'))->handle(app(SuggestionBoard::class));
    expect(SocialSuggestion::withoutGlobalScopes()->count())->toBe(0);

    boardChannel($site, SocialNetwork::Facebook);
    (new SuggestPostShare($post->id, 'post_refreshed', 'Refreshed'))->handle(app(SuggestionBoard::class));
    $s = SocialSuggestion::withoutGlobalScopes()->firstOrFail();
    expect($s->snapshot)->toMatchArray(['question' => 'why pumps run', 'also' => 2]);
});

it('a rule\'s automatic share is recorded as an auto row the board shows, with its posts', function () {
    Queue::fake();
    $site = boardSite();
    $fb = boardChannel($site, SocialNetwork::Facebook);
    $post = boardPost($site);
    $board = app(SuggestionBoard::class);

    $auto = $board->recordAuto($post, SocialTrigger::Weather, 'Heavy rain: 1.4 in.', ['lead' => 'Storms are here.'], [SocialNetwork::Facebook], null);
    SocialPost::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'social_channel_id' => $fb->id, 'social_suggestion_id' => $auto->id, 'subject_type' => 'post', 'subject_id' => $post->id,
        'network' => SocialNetwork::Facebook, 'trigger' => SocialTrigger::Weather, 'status' => SocialPostStatus::Sent, 'copy' => 'x', 'sent_at' => Carbon::now(),
    ]);

    $decided = $board->decided($site->id);
    expect($decided[0]['status'])->toBe('auto')->and($decided[0]['posts'][0]['status'])->toBe('sent')->and($decided[0]['snapshot']['lead'])->toBe('Storms are here.');
});
