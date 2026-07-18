<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\HeaderMenu;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use App\Publishing\Chrome\SiteProfileAssembler;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

function hmSite(): Site
{
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    session(['guided_site_id' => $site->id]);

    return $site;
}

function hmPage(Site $site, string $slug, string $title, array $extra = []): Content
{
    return Content::withoutGlobalScope(SiteScope::class)->create(array_merge([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Utility,
        'title' => $title, 'slug' => $slug, 'version' => 1,
    ], $extra));
}

function navLabels(Site $site): array
{
    return array_column(app(SiteProfileAssembler::class)->assemble($site->fresh())['nav'], 'label');
}

it('reorders the MAIN menu with move up/down and the assembler reflects it', function () {
    $site = hmSite();
    // Distinct created_at so the default (unordered) sequence is deterministic: About, Contact, Areas.
    hmPage($site, 'about', 'About', ['created_at' => now()->subMinutes(3)]);
    hmPage($site, 'contact', 'Contact', ['created_at' => now()->subMinutes(2)]);
    hmPage($site, 'areas-we-serve', 'Areas We Serve', ['created_at' => now()->subMinutes(1)]);

    // Default (no nav_order) → creation order.
    expect(navLabels($site))->toBe(['About', 'Contact', 'Areas We Serve']);

    // Move Areas to the top: down-neighbours shift. Grab its id.
    $areas = Content::withoutGlobalScope(SiteScope::class)->where('slug', 'areas-we-serve')->firstOrFail();
    $page = Livewire::test(HeaderMenu::class);
    $page->call('moveMainUp', $areas->id);  // Areas: index 2 → 1
    $page->call('moveMainUp', $areas->id);  // Areas: index 1 → 0

    expect(navLabels($site))->toBe(['Areas We Serve', 'About', 'Contact']);
});

it('adds, reorders, and removes services in the services bar', function () {
    $site = hmSite();
    $a = hmPage($site, 'svc-a', 'Alpha', ['page_type' => PageType::Service]);
    $b = hmPage($site, 'svc-b', 'Bravo', ['page_type' => PageType::Service]);

    $page = Livewire::test(HeaderMenu::class);
    $page->call('addService', $a->id);   // order 1
    $page->call('addService', $b->id);   // order 2

    $services = fn () => array_column(app(SiteProfileAssembler::class)->assemble($site->fresh())['services'], 'label');
    expect($services())->toBe(['Alpha', 'Bravo']);

    $page->call('moveServiceDown', $a->id); // Alpha → after Bravo
    expect($services())->toBe(['Bravo', 'Alpha']);

    $page->call('removeService', $b->id);
    expect($b->fresh()->nav_featured)->toBeFalse();
    // Alpha remains pinned.
    expect($services())->toBe(['Alpha']);
});

it('shows the builder items and the Portfolio action exists', function () {
    $site = hmSite();
    hmPage($site, 'contact', 'Contact');

    Livewire::test(HeaderMenu::class)->assertOk()->assertSee('Main menu')->assertSee('Contact');
    Livewire::test(ListSites::class)->assertTableActionExists('headerMenu');
});
