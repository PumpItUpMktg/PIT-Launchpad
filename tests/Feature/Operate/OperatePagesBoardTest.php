<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateCorePages;
use App\Filament\Pages\Operate\OperateLocationPages;
use App\Filament\Pages\Operate\OperateServicePages;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Operate\PagesBoard;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

function pbSite(): Site
{
    return Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
}

function pbPage(Site $site, PageType $type, ContentStatus $status, string $title, array $extra = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => $type,
        'status' => $status, 'title' => $title, 'slug' => Str::slug($title),
        'published_at' => $status === ContentStatus::Published ? now()->subDays(2) : null,
        'slot_payload' => $status === ContentStatus::Candidate ? [] : ['hero' => 'x'],
    ], $extra));
}

it('each family board carries ONLY its own pages — work lane + live lane split by status', function () {
    $site = pbSite();
    // Core: one working, one live. Service: one working. Location: one live town.
    pbPage($site, PageType::Utility, ContentStatus::NeedsReview, 'About Us');
    pbPage($site, PageType::Home, ContentStatus::Published, 'Homepage');
    pbPage($site, PageType::Service, ContentStatus::Candidate, 'French Drains');
    $trooper = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'served_towns' => []]);
    pbPage($site, PageType::Location, ContentStatus::Published, 'Norristown', ['parent_location_id' => $trooper->id]);

    $board = app(PagesBoard::class);

    $core = $board->core($site);
    expect(collect($core['work'])->pluck('title')->all())->toBe(['About Us'])
        ->and(collect($core['live'])->pluck('title')->all())->toBe(['Homepage']);

    $services = $board->services($site);
    expect(collect($services['work'])->pluck('title')->all())->toBe(['French Drains'])
        ->and($services['live'])->toBe([]);

    $locations = $board->locations($site);
    expect($locations['work'])->toBe([])                        // the town page is live, not working
        ->and($locations['live']['groups'][0]['towns'][0]['title'])->toBe('Norristown');
});

it('the boards render and the work-lane primary drives the existing paths (approve → publish)', function () {
    Queue::fake();
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    $draft = pbPage($site, PageType::Utility, ContentStatus::NeedsReview, 'About Us');
    pbPage($site, PageType::Home, ContentStatus::Published, 'Homepage');

    $page = Livewire::test(OperateCorePages::class)
        ->assertOk()
        ->assertSee('About Us')      // work lane
        ->assertSee('Homepage')      // live lane
        ->call('approve', $draft->id);

    expect($draft->fresh()->status)->toBe(ContentStatus::Approved);

    $page->call('publish', $draft->id);
    Queue::assertPushed(PublishContent::class);
});

it('a live card takes down back to the work lane of the SAME board (state-driven membership)', function () {
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    $live = pbPage($site, PageType::Service, ContentStatus::Published, 'Sump Pump Installation', ['wp_post_id' => null]);

    Livewire::test(OperateServicePages::class)->call('takeDown', $live->id);

    // Not on WP → marked approved (ready to republish); it now lives in the work lane.
    $board = app(PagesBoard::class)->services($site->fresh());
    expect($live->fresh()->status)->toBe(ContentStatus::Approved)
        ->and(collect($board['work'])->pluck('title')->all())->toContain('Sump Pump Installation')
        ->and($board['live'])->toBe([]);
});

it('the locations board keeps the orphan-assignment controls (parent pin only)', function () {
    $site = pbSite();
    session(['guided_site_id' => $site->id]);
    $trooper = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'served_towns' => []]);
    $orphan = pbPage($site, PageType::Location, ContentStatus::Published, 'Doylestown');

    Livewire::test(OperateLocationPages::class)
        ->assertOk()
        ->call('assignLocation', $orphan->id, $trooper->id);

    expect($orphan->fresh()->parent_location_id)->toBe($trooper->id)
        ->and($orphan->fresh()->location_id)->toBeNull(); // the composeLocation pin is never touched
});
