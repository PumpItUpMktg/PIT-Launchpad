<?php

use App\Enums\SocialNetwork;
use App\Enums\SocialPostStatus;
use App\Enums\SocialTrigger;
use App\Models\Location;
use App\Models\Site;
use App\Models\SocialChannel;
use App\Models\SocialPost;
use App\Social\Provider\MockSocialProvider;
use App\Social\Provider\SocialProvider;
use App\Social\Provider\SocialProviderException;
use App\Social\Provider\UploadPostSocialProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function upProvider(): UploadPostSocialProvider
{
    return new UploadPostSocialProvider(app(HttpFactory::class), 'agency-key', 'https://api.upload-post.com', 30, pollSeconds: 0);
}

/** A multipart part with this exact name and value is in the body (Guzzle writes a Content-Length line between them). */
function upPart(string $body, string $name, string $value): bool
{
    return preg_match('/name="'.preg_quote($name, '/').'"\r\n(?:[^\r\n]*\r\n)*?\r\n'.preg_quote($value, '/').'\r\n/', $body) === 1;
}

function upSite(): Site
{
    return Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example', 'social_profile' => 'sump-pump-gurus']);
}

function upPost(Site $site, SocialChannel $channel, array $overrides = []): SocialPost
{
    return SocialPost::withoutGlobalScopes()->create(array_merge([
        'site_id' => $site->id, 'social_channel_id' => $channel->id, 'subject_type' => 'job', 'subject_id' => '01JOB',
        'network' => $channel->network, 'trigger' => SocialTrigger::JobPublished, 'status' => SocialPostStatus::Queued,
        'copy' => "Pump replaced in Nutley.\nLearn more: https://spg.example/jobs/x?utm_source=gbp",
        'hashtags' => ['SumpPumpRepair', 'NutleyNJ'], 'image_url' => 'https://cdn.spg.example/jobs/x.jpg',
        'link' => 'https://spg.example/jobs/x?utm_source=gbp', 'cta' => 'Learn more',
    ], $overrides));
}

it('reads the tenant profile\'s connected accounts: a channel per GBP location (paged), the Page and the Instagram account; nothing without a profile', function () {
    $site = upSite();
    Http::fake([
        'api.upload-post.com/api/uploadposts/users/sump-pump-gurus' => Http::response(['success' => true, 'profile' => [
            'username' => 'sump-pump-gurus',
            'social_accounts' => [
                'google_business' => ['username' => 'acct-1', 'display_name' => 'Sump Pump Gurus'],
                'facebook' => ['username' => '1029384756', 'display_name' => 'Sump Pump Gurus'],
                'instagram' => '',   // not connected
                'tiktok' => '',
            ],
        ]]),
        'api.upload-post.com/api/uploadposts/google-business/locations?profile=sump-pump-gurus&cursor=c2' => Http::response(['success' => true, 'locations' => [
            ['name' => 'accounts/1/locations/333', 'title' => 'Sump Pump Gurus | Trooper', 'account_id' => 'a'],
        ]]),
        'api.upload-post.com/api/uploadposts/google-business/locations?profile=sump-pump-gurus' => Http::response(['success' => true, 'incomplete' => true, 'next_cursor' => 'c2', 'locations' => [
            ['name' => 'accounts/1/locations/111', 'title' => 'Sump Pump Gurus | Montclair', 'account_id' => 'a'],
            ['name' => 'accounts/1/locations/222', 'title' => 'Sump Pump Gurus | Bedminster', 'account_id' => 'a'],
        ]]),
    ]);

    $profiles = upProvider()->profiles($site);

    expect(array_map(fn ($p) => [$p->id, $p->network->value, $p->name], $profiles))->toBe([
        ['1029384756', 'facebook', 'Sump Pump Gurus'],
        ['accounts/1/locations/111', 'gbp', 'Sump Pump Gurus | Montclair'],
        ['accounts/1/locations/222', 'gbp', 'Sump Pump Gurus | Bedminster'],
        ['accounts/1/locations/333', 'gbp', 'Sump Pump Gurus | Trooper'],
    ]);
    Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Apikey agency-key'));

    expect(upProvider()->profiles(Site::factory()->create(['social_profile' => null])))->toBe([]);
    Http::assertSentCount(3);
});

it('publishes a job photo to the office\'s GBP location with the link as the Learn-more button, no hashtags on GBP', function () {
    $site = upSite();
    $office = Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair office']);
    $channel = SocialChannel::withoutGlobalScopes()->create(['site_id' => $site->id, 'location_id' => $office->id, 'network' => SocialNetwork::Gbp, 'provider' => 'upload_post', 'provider_channel_id' => 'accounts/1/locations/111']);
    $post = upPost($site, $channel);
    Http::fake(['api.upload-post.com/api/upload_photos' => Http::response(['success' => true, 'results' => ['google_business' => [
        'success' => true, 'post_id' => 'accounts/1/locations/111/localPosts/777', 'url' => 'https://local.google.com/p/777',
    ]], 'usage' => ['count' => 3, 'limit' => 500]])]);

    $result = upProvider()->publish($channel, $post);

    expect($result->providerPostId)->toBe('accounts/1/locations/111/localPosts/777')
        ->and($result->permalink)->toBe('https://local.google.com/p/777');
    Http::assertSent(function (Request $r): bool {
        $body = $r->body();

        return str_ends_with($r->url(), '/api/upload_photos')
            && $r->hasHeader('Idempotency-Key', 'lp-'.SocialPost::withoutGlobalScopes()->value('id'))
            && upPart($body, 'user', 'sump-pump-gurus')
            && upPart($body, 'platform[]', 'google_business')
            && upPart($body, 'gbp_location_id', 'accounts/1/locations/111')
            && upPart($body, 'gbp_cta_type', 'LEARN_MORE')
            && upPart($body, 'gbp_cta_url', 'https://spg.example/jobs/x?utm_source=gbp')
            && upPart($body, 'photos[]', 'https://cdn.spg.example/jobs/x.jpg')
            && str_contains($body, 'Pump replaced in Nutley.')
            && ! str_contains($body, '#SumpPumpRepair');   // GBP renders hashtags as noise — the composer keeps them off
    });
});

it('a Facebook share carries the hashtags in the caption; a share with no image goes as a text post; a refusal, a limit and an API-key error each surface', function () {
    $site = upSite();
    $fb = SocialChannel::withoutGlobalScopes()->create(['site_id' => $site->id, 'network' => SocialNetwork::Facebook, 'provider' => 'upload_post', 'provider_channel_id' => '1029384756']);
    $textPost = upPost($site, $fb, ['image_url' => null]);
    Http::fake([
        'api.upload-post.com/api/upload_text' => Http::sequence()
            ->push(['success' => true, 'results' => ['facebook' => ['success' => true, 'post_id' => '1029384756_42', 'url' => 'https://facebook.com/1029384756/posts/42']]])
            ->push(['success' => true, 'results' => ['facebook' => ['success' => false, 'error' => 'Page token expired']]])
            ->push(['success' => false, 'message' => 'This upload would exceed your monthly limit.', 'usage' => ['count' => 500, 'limit' => 500]], 429)
            ->push(['success' => false, 'message' => 'Invalid or expired token'], 401),
    ]);

    $result = upProvider()->publish($fb, $textPost);
    expect($result->providerPostId)->toBe('1029384756_42')->and($result->permalink)->toBe('https://facebook.com/1029384756/posts/42');
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/api/upload_text')
        && str_contains($r->body(), '#SumpPumpRepair #NutleyNJ')
        && upPart($r->body(), 'link_url', 'https://spg.example/jobs/x?utm_source=gbp'));

    expect(fn () => upProvider()->publish($fb, $textPost))->toThrow(SocialProviderException::class, 'Page token expired');
    expect(fn () => upProvider()->publish($fb, $textPost))->toThrow(SocialProviderException::class, 'limit reached');
    expect(fn () => upProvider()->publish($fb, $textPost))->toThrow(SocialProviderException::class, '401');
});

it('a request Upload-Post takes async is polled on the status endpoint until the platform entry ends', function () {
    $site = upSite();
    $fb = SocialChannel::withoutGlobalScopes()->create(['site_id' => $site->id, 'network' => SocialNetwork::Facebook, 'provider' => 'upload_post', 'provider_channel_id' => '1029384756']);
    $post = upPost($site, $fb);
    Http::fake([
        'api.upload-post.com/api/upload_photos' => Http::response(['success' => true, 'message' => 'Upload initiated successfully in background.', 'request_id' => 'req-9', 'total_platforms' => 1]),
        'api.upload-post.com/api/uploadposts/status*' => Http::sequence()
            ->push(['request_id' => 'req-9', 'status' => 'in_progress', 'completed' => 0, 'total' => 1, 'results' => []])
            ->push(['request_id' => 'req-9', 'status' => 'completed', 'completed' => 1, 'total' => 1, 'results' => [['platform' => 'facebook', 'status' => 'completed', 'success' => true, 'platform_post_id' => '1029384756_43', 'post_url' => 'https://facebook.com/1029384756/posts/43']]]),
    ]);

    $result = upProvider()->publish($fb, $post);

    expect($result->providerPostId)->toBe('1029384756_43')->and($result->permalink)->toBe('https://facebook.com/1029384756/posts/43');
    Http::assertSentCount(3);
});

it('binds the Upload-Post adapter when the agency key is set, the mock otherwise', function () {
    config()->set('services.upload_post.api_key', null);
    app()->forgetInstance(SocialProvider::class);
    expect(app(SocialProvider::class))->toBeInstanceOf(MockSocialProvider::class);

    config()->set('services.upload_post.api_key', 'agency-key');
    app()->forgetInstance(SocialProvider::class);
    expect(app(SocialProvider::class))->toBeInstanceOf(UploadPostSocialProvider::class);

    config()->set('services.upload_post.api_key', null);
    app()->forgetInstance(SocialProvider::class);
});
