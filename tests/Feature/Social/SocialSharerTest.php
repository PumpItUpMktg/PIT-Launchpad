<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\JobStatus;
use App\Enums\SocialNetwork;
use App\Enums\SocialPostStatus;
use App\Enums\SocialTrigger;
use App\JobCapture\Publishing\JobPublisher;
use App\Jobs\SendSocialPost;
use App\Jobs\ShareJobSocially;
use App\Jobs\SharePostSocially;
use App\Jobs\SuggestPostShare;
use App\Models\Content;
use App\Models\Job;
use App\Models\JobCity;
use App\Models\Location;
use App\Models\Site;
use App\Models\SocialChannel;
use App\Models\SocialPost;
use App\Publishing\PublishContentService;
use App\Social\Provider\MockSocialProvider;
use App\Social\Provider\SocialProvider;
use App\Social\SocialSharer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PublishHarness;

function sharerSite(): Site
{
    return Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
}

function sharerChannel(Site $site, SocialNetwork $network, ?Location $location = null, array $overrides = []): SocialChannel
{
    return SocialChannel::withoutGlobalScopes()->create(array_merge([
        'site_id' => $site->id, 'location_id' => $location?->id, 'network' => $network, 'provider_channel_id' => $network->value.'-'.($location?->id ?? 'site'),
    ], $overrides));
}

function sharerJob(Site $site, ?JobCity $city = null): Job
{
    return Job::factory()->create([
        'site_id' => $site->id, 'status' => JobStatus::Published, 'job_city_id' => $city?->id,
        'post_title' => 'Pump Replacement', 'enhanced_description' => 'Replaced the pump.', 'wp_post_id' => 3,
    ])->fresh(['site', 'city', 'jobTypes']);
}

function sharerPost(Site $site, array $meta = []): Content
{
    return Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published, 'title' => 'Cost Post', 'slug' => 'cost-post',
        'body' => '<p>Body.</p>', 'wp_post_id' => 4, 'published_at' => now()->subDays(10), 'meta' => $meta,
    ])->fresh(['site', 'silo', 'renderJobs']);
}

it('a published job shares to the serving location\'s GBP and the site-level Page — not another location\'s GBP, not a disabled channel', function () {
    Queue::fake();
    $site = sharerSite();
    $home = Location::factory()->create(['site_id' => $site->id, 'name' => 'Home', 'lat' => 40.85, 'lng' => -74.83]);
    $far = Location::factory()->create(['site_id' => $site->id, 'name' => 'Far', 'lat' => 41.5, 'lng' => -74.0]);
    $homeGbp = sharerChannel($site, SocialNetwork::Gbp, $home);
    sharerChannel($site, SocialNetwork::Gbp, $far);
    sharerChannel($site, SocialNetwork::Gbp, null, ['provider_channel_id' => 'gbp-unmatched']);   // a GBP profile the sync could not place
    $page = sharerChannel($site, SocialNetwork::Facebook);
    sharerChannel($site, SocialNetwork::Instagram, null, ['enabled' => false]);
    $job = sharerJob($site);   // coordinates from the factory sit nearer Home
    $job->forceFill(['lat_true' => 40.86, 'lng_true' => -74.82])->save();

    $rows = app(SocialSharer::class)->shareJob($job->fresh(['site', 'city', 'jobTypes']), SocialTrigger::JobPublished);

    expect(collect($rows)->pluck('social_channel_id')->sort()->values()->all())->toBe(collect([$homeGbp->id, $page->id])->sort()->values()->all())
        ->and($rows[0]->status)->toBe(SocialPostStatus::Queued)
        ->and($rows[0]->subject_type)->toBe('job')->and($rows[0]->subject_id)->toBe($job->id);
    Queue::assertPushed(SendSocialPost::class, 2);

    // A re-publish of the same job never re-shares.
    expect(app(SocialSharer::class)->shareJob($job, SocialTrigger::JobPublished))->toBe([]);
    // A manual share always goes.
    expect(app(SocialSharer::class)->shareJob($job, SocialTrigger::Manual))->toHaveCount(2);
});

it('sends through the provider: sent with the provider id + permalink; a refusal lands as failed and retry re-queues it', function () {
    Queue::fake();
    $site = sharerSite();
    $channel = sharerChannel($site, SocialNetwork::Facebook);
    $post = sharerPost($site);
    $sharer = app(SocialSharer::class);
    /** @var MockSocialProvider $mock */
    $mock = app(SocialProvider::class);

    [$row] = $sharer->sharePost($post, SocialTrigger::PostPublished);
    $sharer->send($row);
    $row->refresh();
    expect($row->status)->toBe(SocialPostStatus::Sent)
        ->and($row->provider_post_id)->toBe('mock-'.$row->id)
        ->and($row->permalink)->toContain('/facebook/')
        ->and($row->sent_at)->not->toBeNull()
        ->and($mock->published)->toHaveCount(1)
        ->and($mock->published[0]['channel'])->toBe($channel->id);

    $mock->failNext('token expired');
    [$second] = $sharer->sharePost($post, SocialTrigger::Manual);
    $sharer->send($second);
    expect($second->fresh()->status)->toBe(SocialPostStatus::Failed)->and($second->fresh()->error)->toBe('token expired');

    $sharer->retry($second->fresh());
    expect($second->fresh()->status)->toBe(SocialPostStatus::Queued);
    // Two pushes, not three: SendSocialPost is unique per row and the faked queue never releases the
    // lock the first dispatch took; on a real worker the failed run released it and the retry pushes.
    Queue::assertPushed(SendSocialPost::class, 2);
});

it('a refresh re-share needs the refresh to be newer than the last share, and honours the cooldown', function () {
    Queue::fake();
    $site = sharerSite();
    sharerChannel($site, SocialNetwork::Facebook);
    $sharer = app(SocialSharer::class);
    $post = sharerPost($site);

    // No refresh mark → nothing.
    expect($sharer->sharePost($post, SocialTrigger::PostRefreshed))->toBe([]);

    // Refreshed after the first share → re-shares (first share long ago, outside the cooldown).
    Carbon::setTestNow('2026-08-01 10:00');
    $sharer->sharePost($post, SocialTrigger::PostPublished);
    Carbon::setTestNow('2026-11-01 10:00');
    $post->forceFill(['meta' => ['question_refresh' => ['question' => 'q', 'answer' => 'a', 'refreshed_at' => '2026-10-30T12:00:00Z']]])->save();
    expect($sharer->sharePost($post->fresh(['site', 'silo', 'renderJobs']), SocialTrigger::PostRefreshed))->toHaveCount(1);

    // The same refresh is not shared twice; a newer refresh inside the cooldown waits.
    expect($sharer->sharePost($post->fresh(['site', 'silo', 'renderJobs']), SocialTrigger::PostRefreshed))->toBe([]);
    Carbon::setTestNow('2026-11-20 10:00');
    $post->forceFill(['meta' => ['question_refresh' => ['question' => 'q', 'answer' => 'a2', 'refreshed_at' => '2026-11-19T12:00:00Z']]])->save();
    expect($sharer->sharePost($post->fresh(['site', 'silo', 'renderJobs']), SocialTrigger::PostRefreshed))->toBe([]);
    Carbon::setTestNow();
});

it('a capped channel schedules the overflow for the next day at the slot hour', function () {
    Queue::fake();
    Carbon::setTestNow('2026-11-07 15:00:00');
    $site = sharerSite();
    sharerChannel($site, SocialNetwork::Facebook, null, ['max_per_day' => 1]);
    $sharer = app(SocialSharer::class);

    [$first] = $sharer->shareJob(sharerJob($site), SocialTrigger::JobPublished);
    [$second] = $sharer->shareJob(sharerJob($site), SocialTrigger::JobPublished);

    expect($first->scheduled_for?->toDateTimeString())->toBe('2026-11-07 15:00:00')
        ->and($second->scheduled_for?->toDateTimeString())->toBe('2026-11-08 14:00:00');
    Carbon::setTestNow();
});

it('the job publisher queues the share after a successful push; the content publisher shares a post\'s first publish and sends a refresh to the board', function () {
    Queue::fake();
    $site = PublishHarness::site();
    Http::fake([
        '*/launchpad/v1/job' => Http::response(['wp_post_id' => 77], 200),
        '*/wp-json/launchpad/v1/content' => Http::response(['wp_post_id' => 123, 'status' => 'publish', 'skipped' => false], 200),
    ]);
    PublishHarness::fakeAdapters();
    $job = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Approved, 'post_title' => 'T', 'enhanced_description' => 'Done.']);

    app(JobPublisher::class)->publish($job);
    Queue::assertPushed(ShareJobSocially::class, fn (ShareJobSocially $j) => $j->jobId === $job->id);

    // A page never shares.
    $page = PublishHarness::approvedPage($site);
    app(PublishContentService::class)->publish($page, 'op');
    Queue::assertNotPushed(SharePostSocially::class);

    // A post: first publish → post_published; a re-push with a refresh mark → post_refreshed; a plain re-push → nothing.
    $post = PublishHarness::approvedPage(PublishHarness::site());
    $post->forceFill(['kind' => ContentKind::Post, 'body' => '<p>Body</p>', 'published_at' => null])->save();
    app(PublishContentService::class)->publish($post->fresh(), 'op');
    Queue::assertPushed(SharePostSocially::class, fn (SharePostSocially $j) => $j->contentId === $post->id && $j->trigger === 'post_published');

    $post = $post->fresh();
    $post->forceFill(['status' => ContentStatus::Approved])->save();
    app(PublishContentService::class)->publish($post->fresh(), 'op');
    Queue::assertPushed(SharePostSocially::class, 1);

    $post = $post->fresh();
    $post->forceFill(['status' => ContentStatus::Approved, 'meta' => array_merge($post->meta ?? [], ['question_refresh' => ['question' => 'how much is it', 'answer' => 'a', 'refreshed_at' => now()->toIso8601String()]])])->save();
    app(PublishContentService::class)->publish($post->fresh(), 'op');
    Queue::assertPushed(SharePostSocially::class, 1);   // no second automatic share …
    Queue::assertPushed(SuggestPostShare::class, fn (SuggestPostShare $j) => $j->contentId === $post->id && $j->trigger === 'post_refreshed' && str_contains($j->reason, 'how much is it'));   // … the refresh goes to the board
});

it('the share jobs only act on live subjects', function () {
    Queue::fake();
    $site = sharerSite();
    sharerChannel($site, SocialNetwork::Facebook);
    $draft = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Review]);
    (new ShareJobSocially($draft->id))->handle(app(SocialSharer::class));
    expect(SocialPost::withoutGlobalScopes()->count())->toBe(0);

    $live = sharerJob($site);
    (new ShareJobSocially($live->id))->handle(app(SocialSharer::class));
    expect(SocialPost::withoutGlobalScopes()->count())->toBe(1);
});
