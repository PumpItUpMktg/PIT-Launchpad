<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\SocialNetwork;
use App\Enums\SocialSuggestionStatus;
use App\Enums\SocialTrigger;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateShares;
use App\Jobs\SendSocialPost;
use App\Jobs\SyncSocialChannels;
use App\Models\Content;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Site;
use App\Models\SocialChannel;
use App\Models\SocialPost;
use App\Models\SocialSuggestion;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Social\ChannelSync;
use App\Social\ProfilePicker;
use App\Social\Provider\AccountProfile;
use App\Social\Provider\MockSocialProvider;
use App\Social\Provider\SocialProfile;
use App\Social\Provider\SocialProvider;
use App\Social\SuggestionBoard;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

function sharesSite(): Site
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    app(ActiveTenant::class)->set($site->id);

    return $site;
}

function sharesPost(Site $site, string $slug = 'why-pumps-run'): Content
{
    return Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published, 'title' => 'Why Pumps Run', 'slug' => $slug, 'body' => '<p>b</p>', 'wp_post_id' => 2,
        'published_at' => now()->subDays(20),
        'meta' => ['question_refresh' => ['question' => 'why does my sump pump run constantly', 'answer' => 'Usually a stuck float.', 'refreshed_at' => now()->toIso8601String()]],
    ]);
}

it('shows the synced channels read-only with Sync now, no typing in', function () {
    $site = sharesSite();
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office']);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Phillipsburg office']);
    /** @var MockSocialProvider $mock */
    $mock = app(SocialProvider::class);
    $mock->connect($site->id, [
        new SocialProfile('g1', SocialNetwork::Gbp, 'SPG Hackettstown'),
        new SocialProfile('g2', SocialNetwork::Gbp, 'SPG Nowhere', '1 Nowhere Rd'),
        new SocialProfile('fb', SocialNetwork::Facebook, 'SPG Page'),
    ]);

    Livewire::test(OperateShares::class)
        ->assertSee('none connected yet')
        ->assertDontSee('Add channel')
        ->call('syncChannels')
        ->assertNotified('3 profile(s) synced')
        ->assertSee('SPG · Hackettstown')
        ->assertSee('1 GBP office')
        ->assertSee('1 unmatched')
        ->assertSee('Facebook · SPG Page')
        ->assertSee('unmatched');
    expect(SocialChannel::withoutGlobalScopes()->where('provider_channel_id', 'g1')->value('location_id'))->toBe($loc->id);
});

it('a suggestion card shows why, the copy, the resolved GBP locations and the Q&A; approve queues the ticked platforms; skip closes', function () {
    Queue::fake();
    $site = sharesSite();
    SocialChannel::withoutGlobalScopes()->create(['site_id' => $site->id, 'network' => SocialNetwork::Facebook, 'provider_channel_id' => 'fb']);
    SocialChannel::withoutGlobalScopes()->create(['site_id' => $site->id, 'network' => SocialNetwork::Instagram, 'provider_channel_id' => 'ig']);
    $post = sharesPost($site);
    $s = app(SuggestionBoard::class)->suggest($post->fresh(['site']), SocialTrigger::PostRefreshed, 'Refreshed to answer “why does my sump pump run constantly”');

    $page = Livewire::test(OperateShares::class)
        ->assertSee('Post refreshed · Refreshed to answer')
        ->assertSee('Why Pumps Run')
        ->assertSee('GBP: every location')
        ->assertSee('Why does my sump pump run constantly?')
        ->assertSee('Usually a stuck float.')
        ->assertSee('GBP Q&amp;A to add by hand', false);
    expect($page->get('picks')[$s->id])->toBe(['facebook' => true, 'instagram' => true]);

    $page->set("picks.{$s->id}.instagram", false)->call('approve', $s->id)->assertNotified('Queued 1 share(s)');
    expect(SocialPost::withoutGlobalScopes()->count())->toBe(1)
        ->and(SocialPost::withoutGlobalScopes()->first()->network)->toBe(SocialNetwork::Facebook)
        ->and($s->fresh()->status)->toBe(SocialSuggestionStatus::Approved);
    Queue::assertPushed(SendSocialPost::class, 1);

    $page->assertSee('approved')->assertSee('Went out');

    // Pick a post by hand → a manual suggestion → skip.
    $other = sharesPost($site, 'other');
    $page->set('pickPostId', $other->id)->call('suggestPost')->assertNotified('Added to the board')->assertSee('Picked by hand');
    $manual = SocialSuggestion::withoutGlobalScopes()->where('status', 'open')->firstOrFail();
    $page->call('skip', $manual->id);
    expect($manual->fresh()->status)->toBe(SocialSuggestionStatus::Skipped);
});

it('approving with nothing ticked asks for a platform', function () {
    $site = sharesSite();
    SocialChannel::withoutGlobalScopes()->create(['site_id' => $site->id, 'network' => SocialNetwork::Facebook, 'provider_channel_id' => 'fb']);
    $s = app(SuggestionBoard::class)->suggest(sharesPost($site)->fresh(['site']), SocialTrigger::Manual, 'Picked');

    Livewire::test(OperateShares::class)->set("picks.{$s->id}.facebook", false)->call('approve', $s->id)->assertNotified('Tick at least one platform');
    expect($s->fresh()->status)->toBe(SocialSuggestionStatus::Open);
});

it('an unmatched GBP channel offers the offices; assigning one ties it (locked) and unassigning frees it', function () {
    $site = sharesSite();
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office']);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair office']);
    /** @var MockSocialProvider $mock */
    $mock = app(SocialProvider::class);
    $mock->connect($site->id, [new SocialProfile('locations/555', SocialNetwork::Gbp, 'Sump Pump Gurus')]);
    app(ChannelSync::class)->sync($site);
    $channel = SocialChannel::withoutGlobalScopes()->where('provider_channel_id', 'locations/555')->firstOrFail();

    Livewire::test(OperateShares::class)
        ->assertSee('unmatched — assign office…')
        ->assertSee('Hackettstown office')
        ->call('assignChannel', $channel->id, $loc->id)
        ->assertNotified()
        ->assertDontSee('unmatched — assign office…');
    expect($channel->fresh()->location_id)->toBe($loc->id)->and($channel->fresh()->location_locked)->toBeTrue();

    Livewire::test(OperateShares::class)->call('assignChannel', $channel->id, '');
    expect($channel->fresh()->location_id)->toBeNull()->and($channel->fresh()->location_locked)->toBeFalse();
});

it('tying a GBP channel to an office is a Super Admin matter — a Site Admin sees the board, not the assign control', function () {
    $site = sharesSite();
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office']);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair office']);   // two offices: the sync cannot place the listing on its own
    /** @var MockSocialProvider $mock */
    $mock = app(SocialProvider::class);
    $mock->connect($site->id, [new SocialProfile('locations/555', SocialNetwork::Gbp, 'Sump Pump Gurus')]);
    app(ChannelSync::class)->sync($site);
    $channel = SocialChannel::withoutGlobalScopes()->where('provider_channel_id', 'locations/555')->firstOrFail();
    expect($channel->location_id)->toBeNull();

    $siteAdmin = User::factory()->create(['role' => UserRole::SiteAdmin]);
    Membership::create(['user_id' => $siteAdmin->id, 'account_id' => $site->account_id, 'site_id' => $site->id, 'role' => UserRole::SiteAdmin]);
    $this->actingAs($siteAdmin);

    Livewire::test(OperateShares::class)
        ->assertSee('Sync now')
        ->assertDontSee('unmatched — assign office…')
        ->assertSee('unmatched')
        ->assertDontSee('Posting profiles')
        ->call('assignChannel', $channel->id, $loc->id);
    expect($channel->fresh()->location_id)->toBeNull();
});

it('a Super Admin sees the tenant\'s posting profiles with what each has connected, and one the account no longer has flagged — from the cache the sync warmed, never a provider call on render', function () {
    $site = sharesSite();
    $site->forceFill(['social_profile' => 'SumpPumpGurus, Gone'])->save();
    /** @var MockSocialProvider $mock */
    $mock = app(SocialProvider::class);
    $mock->accountProfiles = [
        new AccountProfile('SumpPumpGurus', ['gbp' => 'Eric Wright', 'facebook' => 'Sump Pump Gurus Corporate']),
        new AccountProfile('OtherClient', ['facebook' => 'Other Co']),
    ];

    // Nothing cached yet: the names show, the connections wait for a sync — the page did not read the provider.
    Livewire::test(OperateShares::class)
        ->assertSee('Posting profiles')
        ->assertSee('SumpPumpGurus')
        ->assertSee('connections show after the next sync')
        ->assertDontSee('Eric Wright');

    (new SyncSocialChannels($site->id))->handle(app(ChannelSync::class), app(ProfilePicker::class));

    Livewire::test(OperateShares::class)
        ->assertSee('Posting profiles')
        ->assertSee('SumpPumpGurus')
        ->assertSee('GBP: Eric Wright · Facebook: Sump Pump Gurus Corporate')
        ->assertSee('not in the provider account')
        ->assertDontSee('OtherClient');
});

it('Sync now queues the provider read on the worker and shows the state until it lands; the job records done or failed', function () {
    $site = sharesSite();
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office']);
    /** @var MockSocialProvider $mock */
    $mock = app(SocialProvider::class);
    $mock->connect($site->id, [new SocialProfile('fb', SocialNetwork::Facebook, 'SPG Page')]);

    Queue::fake();
    Livewire::test(OperateShares::class)
        ->call('syncChannels')
        ->assertNotified('Sync queued')
        ->assertSee('Syncing…')
        ->assertSee('Reading the provider');
    Queue::assertPushed(SyncSocialChannels::class, fn (SyncSocialChannels $j): bool => $j->siteId === (string) $site->id);
    expect(SyncSocialChannels::status($site->id)['state'])->toBe('queued');

    // A second click while one is in flight queues nothing more.
    Livewire::test(OperateShares::class)->call('syncChannels');
    Queue::assertPushed(SyncSocialChannels::class, 1);

    (new SyncSocialChannels($site->id))->handle(app(ChannelSync::class), app(ProfilePicker::class));
    $state = SyncSocialChannels::status($site->id);
    expect($state['state'])->toBe('done')->and($state['result']['synced'])->toBe(1);
    Livewire::test(OperateShares::class)->assertSee('Sync now')->assertSee('1 profile(s), 0 GBP matched')->assertSee('Facebook · SPG Page');

    // A site that vanished before the worker got to it leaves no state behind.
    (new SyncSocialChannels('01HZZZZZZZZZZZZZZZZZZZZZZZ'))->handle(app(ChannelSync::class), app(ProfilePicker::class));
    expect(SyncSocialChannels::status('01HZZZZZZZZZZZZZZZZZZZZZZZ')['state'])->toBe('idle');
});

it('a Super Admin can set a channel aside — it leaves the row, sits under Ignored, survives a sync, takes no share — and restore it; a Site Admin cannot', function () {
    $site = sharesSite();
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office']);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair office']);
    /** @var MockSocialProvider $mock */
    $mock = app(SocialProvider::class);
    $mock->connect($site->id, [
        new SocialProfile('locations/555', SocialNetwork::Gbp, 'Sump Pump Today | Pottstown'),
        new SocialProfile('fb', SocialNetwork::Facebook, 'SPG Page'),
    ]);
    app(ChannelSync::class)->sync($site);
    $channel = SocialChannel::withoutGlobalScopes()->where('provider_channel_id', 'locations/555')->firstOrFail();

    Livewire::test(OperateShares::class)
        ->assertSee('Sump Pump Today | Pottstown')
        ->assertSee('1 unmatched')
        ->assertDontSee('Ignored (')
        ->call('ignoreChannel', $channel->id)
        ->assertNotified()
        ->assertSee('Ignored (1)')
        ->assertDontSee('unmatched — assign office…');
    expect($channel->fresh()->ignored_at)->not->toBeNull()
        ->and(SocialChannel::withoutGlobalScopes()->takesShares()->pluck('provider_channel_id')->all())->toBe(['fb']);

    // The sync keeps the operator's decision.
    app(ChannelSync::class)->sync($site);
    expect($channel->fresh()->ignored_at)->not->toBeNull();

    Livewire::test(OperateShares::class)->call('restoreChannel', $channel->id)->assertNotified()->assertDontSee('Ignored (');
    expect($channel->fresh()->ignored_at)->toBeNull();

    $siteAdmin = User::factory()->create(['role' => UserRole::SiteAdmin]);
    Membership::create(['user_id' => $siteAdmin->id, 'account_id' => $site->account_id, 'site_id' => $site->id, 'role' => UserRole::SiteAdmin]);
    $this->actingAs($siteAdmin);
    Livewire::test(OperateShares::class)->call('ignoreChannel', $channel->id);
    expect($channel->fresh()->ignored_at)->toBeNull();
});
