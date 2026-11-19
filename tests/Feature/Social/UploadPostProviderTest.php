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
use Illuminate\Http\Client\ConnectionException;
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
                'google_business' => ['display_name' => 'Eric Wright', 'handle' => 'Eric Wright'],
                'facebook' => ['display_name' => 'Eric Lite', 'handle' => 'Eric Lite'],   // the person who connected it — not the Page
                'instagram' => '',   // not connected
                'tiktok' => '',
            ],
            // The Page the profile publishes to is a profile-level field (as the live account shows).
            'facebook_page_id' => '102255308709082',
            'facebook_page_name' => 'Sump Pump Gurus Corporate',
            'google_business_location_id' => 'locations/111',
        ]]),
        'api.upload-post.com/api/uploadposts/google-business/locations?profile=sump-pump-gurus&cursor=c2' => Http::response(['success' => true, 'locations' => [
            ['name' => 'accounts/1/locations/333', 'title' => 'Sump Pump Gurus | Trooper', 'account_id' => 'a'],
        ]]),
        'api.upload-post.com/api/uploadposts/google-business/locations?profile=sump-pump-gurus' => Http::response(['success' => true, 'incomplete' => true, 'next_cursor' => 'c2', 'locations' => [
            ['name' => 'accounts/1/locations/111', 'title' => 'Sump Pump Gurus | Montclair', 'account_id' => 'a'],
            ['name' => 'accounts/1/locations/222', 'title' => 'Sump Pump Gurus', 'account_id' => 'a'],   // named without its town
        ]]),
        // Each location's own record: the street and the Google place id — what the channel sync matches on first.
        'api.upload-post.com/api/uploadposts/google-business/location-info?user=sump-pump-gurus&location_id=accounts%2F1%2Flocations%2F111&read_mask=*' => Http::response(['success' => true, 'info' => [
            'place_id' => 'ChIJ-montclair', 'address' => ['addressLines' => ['50 South Park Street'], 'locality' => 'Montclair', 'administrativeArea' => 'New Jersey', 'postalCode' => '07042'],
        ]]),
        'api.upload-post.com/api/uploadposts/google-business/location-info?user=sump-pump-gurus&location_id=accounts%2F1%2Flocations%2F222&read_mask=*' => Http::response(['success' => true, 'info' => [
            'place_id' => 'ChIJ-bedminster', 'address' => ['addressLines' => ['432 U.S. 202'], 'locality' => 'Bedminster', 'administrativeArea' => 'New Jersey', 'postalCode' => '07921'],
        ]]),
        'api.upload-post.com/api/uploadposts/google-business/location-info?user=sump-pump-gurus&location_id=accounts%2F1%2Flocations%2F333&read_mask=*' => Http::response(['success' => false, 'message' => 'Google timed out'], 502),   // a failed read leaves the name as the fallback
    ]);

    $profiles = upProvider()->profiles($site);

    expect(array_map(fn ($p) => [$p->id, $p->network->value, $p->name, $p->address, $p->placeId], $profiles))->toBe([
        ['102255308709082', 'facebook', 'Sump Pump Gurus Corporate', null, null],
        ['accounts/1/locations/111', 'gbp', 'Sump Pump Gurus | Montclair', '50 South Park Street, Montclair, New Jersey 07042', 'ChIJ-montclair'],
        ['accounts/1/locations/222', 'gbp', 'Sump Pump Gurus', '432 U.S. 202, Bedminster, New Jersey 07921', 'ChIJ-bedminster'],
        ['accounts/1/locations/333', 'gbp', 'Sump Pump Gurus | Trooper', null, null],
    ]);
    Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Apikey agency-key'));

    expect(upProvider()->profiles(Site::factory()->create(['social_profile' => null])))->toBe([]);
    Http::assertSentCount(6);   // profile + two location pages + three location reads; nothing for a site with no profile
});

it('lists the account\'s profiles with what each has connected — the Page name over the person who connected it, the Instagram handle, the Google account', function () {
    Http::fake([
        'api.upload-post.com/api/uploadposts/users' => Http::response(['success' => true, 'profiles' => [
            ['username' => 'SPG-Pennsylvania', 'social_accounts' => ['google_business' => ['display_name' => 'Sump Pump Gurus'], 'facebook' => '', 'instagram' => '']],
            ['username' => 'SumpPumpGurus', 'facebook_page_id' => '102255308709082', 'facebook_page_name' => 'Sump Pump Gurus Corporate',
                'social_accounts' => ['google_business' => ['display_name' => 'Eric Wright'], 'facebook' => ['display_name' => 'Eric Lite'], 'instagram' => ['username' => 'sumppumpgurus'], 'tiktok' => '']],
            ['username' => 'empty', 'social_accounts' => ['facebook' => '', 'instagram' => '']],
        ]]),
    ]);

    $profiles = upProvider()->accountProfiles();

    expect(array_map(fn ($p) => $p->name, $profiles))->toBe(['empty', 'SPG-Pennsylvania', 'SumpPumpGurus'])
        ->and($profiles[2]->connected)->toBe(['facebook' => 'Sump Pump Gurus Corporate', 'instagram' => 'sumppumpgurus', 'gbp' => 'Eric Wright'])
        ->and($profiles[2]->summary())->toBe('GBP: Eric Wright · Facebook: Sump Pump Gurus Corporate · Instagram: sumppumpgurus')
        ->and($profiles[1]->summary())->toBe('GBP: Sump Pump Gurus')
        ->and($profiles[1]->has(SocialNetwork::Facebook))->toBeFalse()
        ->and($profiles[0]->summary())->toBe('nothing connected');
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
            && upPart($body, 'gbp_upload_to_gallery', 'true')   // a job photo also lands in the location's gallery
            && upPart($body, 'gbp_media_category', 'AT_WORK')
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
        && upPart($r->body(), 'facebook_page_id', '1029384756')
        && upPart($r->body(), 'link_url', 'https://spg.example/jobs/x?utm_source=gbp'));

    $caught = fn () => rescue(fn () => upProvider()->publish($fb, $textPost), fn (Throwable $e) => $e, false);
    $refusal = $caught();
    $monthly = $caught();
    $badKey = $caught();
    expect($refusal)->toBeInstanceOf(SocialProviderException::class)->and($refusal->getMessage())->toContain('Page token expired')->and($refusal->transient)->toBeFalse()
        ->and($monthly->getMessage())->toContain('limit reached')->and($monthly->transient)->toBeFalse()
        ->and($badKey->getMessage())->toContain('401')->and($badKey->transient)->toBeFalse();
});

it('a 5xx, a per-minute 429 and a dropped connection are transient (one more try); nothing else is', function () {
    $site = upSite();
    $fb = SocialChannel::withoutGlobalScopes()->create(['site_id' => $site->id, 'network' => SocialNetwork::Facebook, 'provider' => 'upload_post', 'provider_channel_id' => '1029384756']);
    $post = upPost($site, $fb, ['image_url' => null]);
    $n = 0;
    Http::fake(['api.upload-post.com/api/upload_text' => function () use (&$n) {
        $n++;

        return match ($n) {
            1 => Http::response(['success' => false, 'error' => 'upstream'], 503),
            2 => Http::response(['success' => false, 'message' => 'Too Many Requests'], 429),
            default => throw new ConnectionException('cURL error 28: timed out'),
        };
    }]);
    $caught = fn () => rescue(fn () => upProvider()->publish($fb, $post), fn (Throwable $e) => $e, false);

    expect($caught()->transient)->toBeTrue()
        ->and($caught()->transient)->toBeTrue()
        ->and($caught()->transient)->toBeTrue();
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

it('a tenant spanning two profiles: channels from both, a listing seen under both kept once (the titled one), each channel carrying its profile, and publishing through the channel\'s own profile', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example', 'social_profile' => 'SumpPumpGurus, SPG-Pennsylvania']);
    $profileA = ['success' => true, 'profile' => ['username' => 'SumpPumpGurus', 'facebook_page_id' => '102255308709082', 'facebook_page_name' => 'Sump Pump Gurus Corporate',
        'social_accounts' => ['google_business' => ['handle' => 'Eric'], 'facebook' => ['handle' => 'Eric'], 'instagram' => '']]];
    $profileB = ['success' => true, 'profile' => ['username' => 'SPG-Pennsylvania',
        'social_accounts' => ['google_business' => ['handle' => 'Sump Pump Gurus'], 'facebook' => '', 'instagram' => '']]];
    $info = fn (string $place, string $street, string $town) => Http::response(['success' => true, 'info' => ['place_id' => $place, 'address' => ['addressLines' => [$street], 'locality' => $town, 'administrativeArea' => 'PA', 'postalCode' => '19000']]]);
    Http::fake([
        'api.upload-post.com/api/uploadposts/users/SumpPumpGurus' => Http::response($profileA),
        'api.upload-post.com/api/uploadposts/users/SPG-Pennsylvania' => Http::response($profileB),
        'api.upload-post.com/api/uploadposts/google-business/locations?profile=SumpPumpGurus' => Http::response(['success' => true, 'locations' => [
            ['name' => 'locations/1', 'title' => 'Sump Pump Gurus'],                 // Doylestown, untitled, under account A
            ['name' => 'locations/2', 'title' => 'Sump Pump Gurus | Montclair'],
        ]]),
        'api.upload-post.com/api/uploadposts/google-business/locations?profile=SPG-Pennsylvania' => Http::response(['success' => true, 'locations' => [
            ['name' => 'locations/2', 'title' => 'Sump Pump Gurus | Montclair'],     // the same listing, same id, under account B
            ['name' => 'locations/3', 'title' => 'Sump Pump Gurus | Doylestown'],   // Doylestown again: another listing id, the SAME place id, titled
            ['name' => 'locations/4', 'title' => 'Sump Pump Gurus | Hackensack'],
        ]]),
        'api.upload-post.com/api/uploadposts/google-business/location-info?user=SumpPumpGurus&location_id=locations%2F1&read_mask=*' => $info('ChIJ-doyle', '4343 West Swamp Road', 'Doylestown'),
        'api.upload-post.com/api/uploadposts/google-business/location-info?user=SumpPumpGurus&location_id=locations%2F2&read_mask=*' => $info('ChIJ-mont', '50 South Park Street', 'Montclair'),
        'api.upload-post.com/api/uploadposts/google-business/location-info?user=SPG-Pennsylvania&location_id=locations%2F3&read_mask=*' => $info('ChIJ-doyle', '4343 West Swamp Road', 'Doylestown'),
        'api.upload-post.com/api/uploadposts/google-business/location-info?user=SPG-Pennsylvania&location_id=locations%2F4&read_mask=*' => $info('ChIJ-hack', '292 Main Street', 'Hackensack'),
        'api.upload-post.com/api/upload_text' => Http::response(['success' => true, 'results' => ['google_business' => ['success' => true, 'post_id' => 'accounts/x/locations/4/localPosts/1']]]),
    ]);

    $profiles = upProvider()->profiles($site);

    expect(array_map(fn ($p) => [$p->id, $p->network->value, $p->name, $p->placeId, $p->account], $profiles))->toBe([
        ['102255308709082', 'facebook', 'Sump Pump Gurus Corporate', null, 'SumpPumpGurus'],
        ['locations/3', 'gbp', 'Sump Pump Gurus | Doylestown', 'ChIJ-doyle', 'SPG-Pennsylvania'],   // the titled twin replaced the untitled one, in its slot
        ['locations/2', 'gbp', 'Sump Pump Gurus | Montclair', 'ChIJ-mont', 'SumpPumpGurus'],       // seen under both; first profile kept
        ['locations/4', 'gbp', 'Sump Pump Gurus | Hackensack', 'ChIJ-hack', 'SPG-Pennsylvania'],
    ]);

    // A channel posts through the profile that exposes it, not the tenant's first profile.
    $hack = SocialChannel::withoutGlobalScopes()->create(['site_id' => $site->id, 'network' => SocialNetwork::Gbp, 'provider' => 'upload_post', 'provider_account' => 'SPG-Pennsylvania', 'provider_channel_id' => 'locations/4']);
    upProvider()->publish($hack, upPost($site, $hack, ['image_url' => null]));
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/api/upload_text') && upPart($r->body(), 'user', 'SPG-Pennsylvania') && upPart($r->body(), 'gbp_location_id', 'locations/4'));

    expect(UploadPostSocialProvider::profilesFor($site))->toBe(['SumpPumpGurus', 'SPG-Pennsylvania'])
        ->and(UploadPostSocialProvider::profileFor($site))->toBe('SumpPumpGurus');
});
