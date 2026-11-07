<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\SocialNetwork;
use App\Enums\SocialSuggestionStatus;
use App\Enums\SocialTrigger;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateShares;
use App\Jobs\SendSocialPost;
use App\Models\Content;
use App\Models\Location;
use App\Models\Site;
use App\Models\SocialChannel;
use App\Models\SocialPost;
use App\Models\SocialSuggestion;
use App\Models\User;
use App\Operator\ActiveTenant;
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
        ->assertSee('Google Business Profile · Hackettstown office')
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
