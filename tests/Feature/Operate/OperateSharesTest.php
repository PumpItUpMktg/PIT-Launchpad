<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\JobStatus;
use App\Enums\SocialNetwork;
use App\Enums\SocialPostStatus;
use App\Enums\SocialTrigger;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateShares;
use App\Jobs\SendSocialPost;
use App\Models\Content;
use App\Models\Job;
use App\Models\Location;
use App\Models\Site;
use App\Models\SocialChannel;
use App\Models\SocialPost;
use App\Models\User;
use App\Operator\ActiveTenant;
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

it('adds channels — a GBP channel needs a location — toggles and caps them', function () {
    $site = sharesSite();
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office']);

    $page = Livewire::test(OperateShares::class)
        ->assertSee('No channels yet')
        ->set('newNetwork', 'gbp')->set('newChannelId', 'gbp-123')->call('addChannel')
        ->assertNotified('A GBP channel belongs to a physical location — pick one')
        ->set('newLocationId', $loc->id)->call('addChannel')
        ->assertNotified('Channel saved')
        ->set('newNetwork', 'facebook')->set('newLocationId', '')->set('newChannelId', 'fb-9')->set('newLabel', 'SPG Page')->call('addChannel')
        ->assertSee('Google Business Profile · Hackettstown office')
        ->assertSee('SPG Page');

    $gbp = SocialChannel::withoutGlobalScopes()->where('provider_channel_id', 'gbp-123')->firstOrFail();
    expect($gbp->location_id)->toBe($loc->id)->and($gbp->enabled)->toBeTrue();

    $page->call('toggleChannel', $gbp->id)->call('setChannelCap', $gbp->id, '2');
    $gbp->refresh();
    expect($gbp->enabled)->toBeFalse()->and($gbp->max_per_day)->toBe(2);
});

it('shares a published job by hand, lists it with its copy, and retries a failed share', function () {
    Queue::fake();
    $site = sharesSite();
    SocialChannel::withoutGlobalScopes()->create(['site_id' => $site->id, 'network' => SocialNetwork::Facebook, 'provider_channel_id' => 'fb-1']);
    $job = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Published, 'post_title' => 'Pit Rebuild', 'enhanced_description' => 'Rebuilt the pit.', 'wp_post_id' => 1]);

    Livewire::test(OperateShares::class)
        ->set('shareJobId', $job->id)->call('shareJob')
        ->assertNotified('Queued 1 share(s)')
        ->assertSee('Pit Rebuild')->assertSee('Manual')->assertSee('queued');
    Queue::assertPushed(SendSocialPost::class, 1);

    $row = SocialPost::withoutGlobalScopes()->firstOrFail();
    $row->forceFill(['status' => SocialPostStatus::Failed, 'error' => 'token expired'])->save();
    Livewire::test(OperateShares::class)->assertSee('token expired')->call('retry', $row->id)->assertNotified('Queued again');
    expect($row->fresh()->status)->toBe(SocialPostStatus::Queued);
});

it('shows the GBP Q&A pair for a question post, and says so when no channel takes a share', function () {
    $site = sharesSite();
    $post = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published, 'title' => 'Why Pumps Run', 'slug' => 'why-pumps-run', 'body' => '<p>b</p>', 'wp_post_id' => 2,
        'meta' => ['question_refresh' => ['question' => 'why does my sump pump run constantly', 'answer' => 'Usually a stuck float.', 'refreshed_at' => now()->toIso8601String()]],
    ]);

    Livewire::test(OperateShares::class)
        ->assertSee('GBP Q&A to add by hand')
        ->assertSee('Why does my sump pump run constantly?')
        ->assertSee('Usually a stuck float.')
        ->set('sharePostId', $post->id)->call('sharePost')
        ->assertNotified('Nothing queued');
    expect(SocialPost::withoutGlobalScopes()->count())->toBe(0)
        ->and(SocialTrigger::Manual->label())->toBe('Manual');
});
