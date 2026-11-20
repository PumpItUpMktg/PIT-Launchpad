<?php

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateBlog;
use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use App\Operate\BlogBoard;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

function newsMark(string $at = '2026-11-20T14:00:00+00:00'): array
{
    return ['candidate_title' => 'Philly stormwater inlets clogged', 'source_name' => 'Inquirer.com', 'refreshed_at' => $at, 'update' => 'u',
        'proposal' => ['before' => ['title' => 'A', 'seo_title' => 's', 'meta_description' => 'm'], 'after' => ['title' => 'A', 'seo_title' => 's', 'meta_description' => 'm'], 'changed' => []]];
}

function questionMark(string $at = '2026-11-07T10:00:00+00:00'): array
{
    return ['query' => 'sump pump installation cost', 'refreshed_at' => $at, 'answer' => 'a', 'faq' => [],
        'proposal' => ['before' => ['title' => 'A', 'seo_title' => 's', 'meta_description' => 'm'], 'after' => ['title' => 'A', 'seo_title' => 's', 'meta_description' => 'm'], 'changed' => []]];
}

it('names the latest refresh (news hook or search question), the date and the count; null for a post never refreshed', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $board = app(BlogBoard::class);

    $never = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'refresh_count' => 0, 'meta' => []]);
    expect($board->refreshBadge($never))->toBeNull();

    $news = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'refresh_count' => 1, 'meta' => ['news_refresh' => newsMark()]]);
    $badge = $board->refreshBadge($news);
    expect($badge['kind'])->toBe('news')->and($badge['count'])->toBe(1)
        ->and($badge['label'])->toBe('↻ Refreshed · news hook · Nov 20')
        ->and($badge['title'])->toContain('Updated with “Philly stormwater inlets clogged” (Inquirer.com)');

    // Both marks: the later one names the kind, the row's refresh_count gives the count.
    $both = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'refresh_count' => 2,
        'meta' => ['news_refresh' => newsMark('2026-11-01T09:00:00+00:00'), 'question_refresh' => questionMark('2026-11-07T10:00:00+00:00')]]);
    $badge = $board->refreshBadge($both);
    expect($badge['kind'])->toBe('question')->and($badge['label'])->toBe('↻ Refreshed ×2 · search question · Nov 7')
        ->and($badge['title'])->toContain('“sump pump installation cost”')->toContain('Refreshed 2 times in all.');

    // An older drafting-engine refresh with no lane mark: the count and date still show.
    $engine = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'refresh_count' => 3, 'last_refreshed_at' => '2026-10-02 08:00:00', 'meta' => []]);
    expect($board->refreshBadge($engine)['label'])->toBe('↻ Refreshed ×3 · content · Oct 2');
});

it('shows the refresh chip on the Approved card, the Publishing strip and the Published card', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Approved, 'title' => 'Approved refreshed post', 'slug' => 'approved-refreshed', 'body' => '<p>x</p>', 'refresh_count' => 1, 'meta' => ['news_refresh' => newsMark()]]);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Approved, 'title' => 'Approved plain post', 'slug' => 'approved-plain', 'body' => '<p>x</p>', 'refresh_count' => 0, 'meta' => []]);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Publishing, 'title' => 'Pushing refreshed post', 'slug' => 'pushing-refreshed', 'body' => '<p>x</p>', 'refresh_count' => 1, 'meta' => ['question_refresh' => questionMark()]]);
    Content::factory()->post()->published()->create(['site_id' => $site->id, 'title' => 'Live refreshed post', 'slug' => 'live-refreshed', 'body' => '<p>x</p>', 'refresh_count' => 2, 'meta' => ['news_refresh' => newsMark()]]);

    Livewire::test(OperateBlog::class)->set('siteFilter', $site->id)->set('tab', 'approved')
        ->assertSee('Approved refreshed post')
        ->assertSee('↻ Refreshed · news hook · Nov 20')
        ->assertSeeHtml('title="Updated with “Philly stormwater inlets clogged” (Inquirer.com)')
        ->assertSee('↻ Refreshed · search question · Nov 7');   // the Publishing strip renders on every tab

    Livewire::test(OperateBlog::class)->set('siteFilter', $site->id)->set('tab', 'published')
        ->assertSee('Live refreshed post')
        ->assertSee('↻ Refreshed ×2 · news hook · Nov 20');
});
