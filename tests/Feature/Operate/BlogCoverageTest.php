<?php

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateBlog;
use App\Jobs\GeneratePost;
use App\Jobs\RefreshPostForNewsAngle;
use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

function coverageFixture(): array
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    $post = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'checklist', 'title' => 'Sump Pump Maintenance Checklist', 'body' => '<p>x</p>', 'meta' => []]);
    $candidate = Content::factory()->post()->create([
        'site_id' => $site->id, 'status' => ContentStatus::Candidate, 'title' => 'Sump Pump Maintenance Checklist This Fall', 'body' => null, 'source_name' => 'Local Tribune', 'relevance_score' => 0.8,
        'meta' => ['coverage' => ['verdict' => 'refresh', 'twin_id' => $post->id, 'twin_title' => $post->title, 'twin_url' => 'https://spg.example/checklist/', 'similarity' => 0.8, 'impressions' => 140, 'clicks' => 3, 'position' => 7.4, 'reason' => 'Already covers this.', 'checked_at' => now()->toIso8601String()]],
    ]);

    return [$site, $post, $candidate];
}

it('shows the coverage verdict on the candidate card, holds a plain Promote, lets Promote anyway through, and Refresh existing post queues the fold', function () {
    Queue::fake();
    [$site, $post, $candidate] = coverageFixture();

    $page = Livewire::test(OperateBlog::class)->set('siteFilter', $site->id)
        ->assertOk()
        ->assertSee('Refresh existing')
        ->assertSee('Sump Pump Maintenance Checklist')
        ->assertSee('pos 7.4')
        ->assertSee('140 impr')
        ->assertSee('Refresh existing post')
        ->assertSee('Promote anyway')
        ->assertSeeHtml("foldIntoTwin('".$candidate->id."')");

    $page->call('promote', (string) $candidate->id);
    Queue::assertNotPushed(GeneratePost::class);
    expect($candidate->fresh()->isGenerating())->toBeFalse();

    $page->call('promote', (string) $candidate->id, true);
    Queue::assertPushed(GeneratePost::class, fn (GeneratePost $j) => $j->contentId === (string) $candidate->id);

    $page->call('foldIntoTwin', (string) $candidate->id);
    Queue::assertPushed(RefreshPostForNewsAngle::class, fn (RefreshPostForNewsAngle $j) => $j->postId === (string) $post->id && $j->candidateId === (string) $candidate->id);
    expect($post->fresh()->meta['generating_at'])->not->toBeNull()->and($candidate->fresh()->meta['coverage']['folding_at'])->not->toBeNull();

    // While it folds the card shows the state and a second click is refused.
    Livewire::test(OperateBlog::class)->set('siteFilter', $site->id)->assertSee('folding into the existing post')
        ->call('foldIntoTwin', (string) $candidate->id);
    Queue::assertPushed(RefreshPostForNewsAngle::class, 1);
});

it('refuses the fold when the twin is not live, and shows the news refresh diff on the review card', function () {
    Queue::fake();
    [$site, $post, $candidate] = coverageFixture();
    $post->forceFill(['status' => ContentStatus::NeedsReview, 'meta' => ['news_refresh' => [
        'candidate_title' => 'Philly stormwater inlets clogged', 'source_name' => 'Inquirer.com', 'refreshed_at' => now()->toIso8601String(), 'update' => 'The Inquirer reports the inlets clog.',
        'proposal' => ['before' => ['title' => 'Sump Pump Maintenance Checklist', 'seo_title' => 's', 'meta_description' => 'm'], 'after' => ['title' => 'Sump Pump Maintenance Checklist for Philly', 'seo_title' => 's', 'meta_description' => 'm'], 'changed' => ['title']],
    ]]])->save();

    Livewire::test(OperateBlog::class)->set('siteFilter', $site->id)->set('tab', 'review')
        ->assertSee('Updated with “Philly stormwater inlets clogged”')
        ->assertSee('(Inquirer.com)')
        ->assertSee('The Inquirer reports the inlets clog.')
        ->assertSee('Sump Pump Maintenance Checklist for Philly')
        ->call('foldIntoTwin', (string) $candidate->id);
    Queue::assertNotPushed(RefreshPostForNewsAngle::class);
});
