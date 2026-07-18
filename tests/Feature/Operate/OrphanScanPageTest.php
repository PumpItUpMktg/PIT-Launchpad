<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OrphanScan;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

function orphanSite(): Site
{
    $site = Site::factory()->create(['brand_name' => 'Orph Co']);
    session(['guided_site_id' => $site->id]);

    return $site;
}

function retiredPage(Site $site, string $slug, array $extra = []): Content
{
    $p = Content::withoutGlobalScope(SiteScope::class)->create(array_merge([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'title' => 'Retired '.$slug, 'slug' => $slug, 'version' => 1, 'published_at' => now(),
    ], $extra));
    $p->delete(); // soft-delete → a retired URL with no redirect

    return $p;
}

it('lists orphan findings for the working tenant with a fix button', function () {
    $site = orphanSite();
    retiredPage($site, 'old-service');

    Livewire::test(OrphanScan::class)
        ->assertOk()
        ->assertSee('URL needs a 301')
        ->assertSee('/old-service')
        ->assertSeeHtml('createRedirect');
});

it('creates a 301 from the retired URL from the page', function () {
    $site = orphanSite();
    $page = retiredPage($site, 'gone-page');

    Livewire::test(OrphanScan::class)->call('createRedirect', $page->id);

    $redirect = Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->firstOrFail();
    expect($redirect->from_url)->toBe('/gone-page')
        ->and($redirect->to_url)->toBe('/')
        ->and((int) $redirect->code)->toBe(301);

    // Now covered → the finding is gone on rescan.
    Livewire::test(OrphanScan::class)->assertDontSee('/gone-page');
});

it('targets the parent hub when the retired page had one', function () {
    $site = orphanSite();
    $hub = Content::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Hub,
        'title' => 'Drains', 'slug' => 'drains', 'version' => 1,
    ]);
    $child = retiredPage($site, 'drains/old-child', ['parent_content_id' => $hub->id]);

    Livewire::test(OrphanScan::class)->call('createRedirect', $child->id);

    expect(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->value('to_url'))->toBe('/drains');
});

it('does not create a duplicate redirect', function () {
    $site = orphanSite();
    $page = retiredPage($site, 'dupe');
    Redirect::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'from_url' => '/dupe', 'to_url' => '/', 'code' => 301, 'source' => 'slug_change', 'status' => 'active',
    ]);

    Livewire::test(OrphanScan::class)->call('createRedirect', $page->id);

    expect(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1);
});

it('exposes the Scan for orphans action on the Portfolio', function () {
    Site::factory()->create();
    Livewire::test(ListSites::class)->assertTableActionExists('scanOrphans');
});
