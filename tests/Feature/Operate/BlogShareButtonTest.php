<?php

use App\Enums\ContentStatus;
use App\Enums\SocialNetwork;
use App\Enums\SocialSuggestionStatus;
use App\Enums\SocialTrigger;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateBlog;
use App\Filament\Pages\Operate\OperateShares;
use App\Models\Content;
use App\Models\Site;
use App\Models\SocialSuggestion;
use App\Models\User;
use App\Social\ChannelSync;
use App\Social\Provider\MockSocialProvider;
use App\Social\Provider\SocialProfile;
use App\Social\Provider\SocialProvider;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

it('links to the Shares board from the blog toolbar, and Share → puts a live post on the board as a manual pick and goes there', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    /** @var MockSocialProvider $mock */
    $mock = app(SocialProvider::class);
    $mock->connect($site->id, [new SocialProfile('fb', SocialNetwork::Facebook, 'SPG Page')]);
    app(ChannelSync::class)->sync($site);
    $post = Content::factory()->post()->published()->create(['site_id' => $site->id, 'title' => 'Live post to share', 'slug' => 'live-share', 'body' => '<p>x</p>', 'meta' => []]);

    $page = Livewire::test(OperateBlog::class)->set('siteFilter', $site->id)->set('tab', 'published')
        ->assertSee('Shares / GBP →')
        ->assertSeeHtml('href="'.OperateShares::getUrl().'"')
        ->assertSeeHtml("shareOnBoard('".$post->id."')");

    $page->call('shareOnBoard', (string) $post->id)->assertRedirect(OperateShares::getUrl());
    $suggestion = SocialSuggestion::withoutGlobalScopes()->where('content_id', $post->id)->sole();
    expect($suggestion->status)->toBe(SocialSuggestionStatus::Open)
        ->and($suggestion->trigger)->toBe(SocialTrigger::Manual)
        ->and($suggestion->reason)->toBe('Picked from the blog board');

    // A second click does not double the open suggestion but still goes to the board.
    Livewire::test(OperateBlog::class)->set('siteFilter', $site->id)->call('shareOnBoard', (string) $post->id)->assertRedirect(OperateShares::getUrl());
    expect(SocialSuggestion::withoutGlobalScopes()->where('content_id', $post->id)->count())->toBe(1);
});

it('refuses a post that is not live, and explains when the site has no channels', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $draft = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::NeedsReview, 'title' => 'Draft', 'slug' => 'draft', 'body' => '<p>x</p>', 'meta' => []]);
    Livewire::test(OperateBlog::class)->set('siteFilter', $site->id)->call('shareOnBoard', (string) $draft->id)->assertNotified('Only a live post can be shared.')->assertNoRedirect();

    $live = Content::factory()->post()->published()->create(['site_id' => $site->id, 'title' => 'Live, no channels', 'slug' => 'live-none', 'body' => '<p>x</p>', 'meta' => []]);
    Livewire::test(OperateBlog::class)->set('siteFilter', $site->id)->call('shareOnBoard', (string) $live->id)->assertNotified('Not added to the Shares board')->assertNoRedirect();
    expect(SocialSuggestion::withoutGlobalScopes()->count())->toBe(0);
});
