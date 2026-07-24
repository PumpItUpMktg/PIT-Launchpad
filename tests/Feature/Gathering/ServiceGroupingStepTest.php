<?php

use App\Enums\ConnectionProvider;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\ServicePageTreatment;
use App\Enums\UserRole;
use App\Filament\Pages\Gathering\ServicesStep;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_setup_enabled', true);
});

function groupingSite(): Site
{
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);

    return $site;
}

function gsService(Site $site, string $name, array $extra = []): Service
{
    return Service::withoutGlobalScope(SiteScope::class)->create(array_merge([
        'site_id' => $site->id, 'name' => $name,
    ], $extra));
}

it('adds a sub-service under a parent, defaulting to Section', function () {
    $site = groupingSite();
    $parent = gsService($site, 'Basement Waterproofing');

    Livewire::test(ServicesStep::class)
        ->set("newChild.{$parent->id}", 'Sump Pump')
        ->call('addSubService', $parent->id);

    $child = Service::withoutGlobalScope(SiteScope::class)->where('name', 'Sump Pump')->first();
    expect($child)->not->toBeNull()
        ->and($child->parent_service_id)->toBe($parent->id)
        ->and($child->page_treatment)->toBe(ServicePageTreatment::Section);
});

it('toggles a sub-service between page and section', function () {
    $site = groupingSite();
    $parent = gsService($site, 'Basement Waterproofing');
    $child = gsService($site, 'Sump Pump', ['parent_service_id' => $parent->id, 'page_treatment' => ServicePageTreatment::Section]);

    $page = Livewire::test(ServicesStep::class);
    $page->call('setTreatment', $child->id, 'page');
    expect($child->fresh()->page_treatment)->toBe(ServicePageTreatment::Page);

    $page->call('setTreatment', $child->id, 'section');
    expect($child->fresh()->page_treatment)->toBe(ServicePageTreatment::Section);
});

it('groups a top-level service under another and ungroups it back', function () {
    $site = groupingSite();
    $hub = gsService($site, 'Basement Waterproofing');
    $loose = gsService($site, 'Sump Pump');

    $page = Livewire::test(ServicesStep::class);
    $page->call('groupUnder', $loose->id, $hub->id);
    expect($loose->fresh()->parent_service_id)->toBe($hub->id)
        ->and($loose->fresh()->page_treatment)->toBe(ServicePageTreatment::Section);

    $page->call('promoteToTop', $loose->id);
    expect($loose->fresh()->parent_service_id)->toBeNull();
});

it('enforces the 2-level cap — a parent-of-children cannot be grouped under another', function () {
    $site = groupingSite();
    $hubA = gsService($site, 'Basement Waterproofing');
    gsService($site, 'Sump Pump', ['parent_service_id' => $hubA->id, 'page_treatment' => ServicePageTreatment::Page]);
    $hubB = gsService($site, 'Crawl Space');

    Livewire::test(ServicesStep::class)->call('groupUnder', $hubA->id, $hubB->id);

    // hubA still top-level — grouping a parent-of-children is refused.
    expect($hubA->fresh()->parent_service_id)->toBeNull();
});

it('frees a removed parent\'s children back to top-level', function () {
    $site = groupingSite();
    $parent = gsService($site, 'Basement Waterproofing');
    $child = gsService($site, 'Sump Pump', ['parent_service_id' => $parent->id, 'page_treatment' => ServicePageTreatment::Page]);

    Livewire::test(ServicesStep::class)->call('removeService', $parent->id);

    expect(Service::withoutGlobalScope(SiteScope::class)->find($parent->id))->toBeNull()
        ->and($child->fresh()->parent_service_id)->toBeNull();
});

it('demoting a service with no live page just sets Section — no takedown or redirect', function () {
    $site = groupingSite();
    $parent = gsService($site, 'Basement Waterproofing');
    $child = gsService($site, 'Sump Pump', ['parent_service_id' => $parent->id, 'page_treatment' => ServicePageTreatment::Page]);

    Livewire::test(ServicesStep::class)->call('setTreatment', $child->id, 'section');

    expect($child->fresh()->page_treatment)->toBe(ServicePageTreatment::Section)
        ->and(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->exists())->toBeFalse();
});

it('demoting a service with a LIVE page takes it down and 301-redirects to the parent', function () {
    $site = groupingSite();
    Connection::factory()->rotated()->create([
        'site_id' => $site->id, 'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://spg.test', 'username' => 'u', 'app_password' => 'p'],
    ]);
    Http::fake(['*/launchpad/v1/content/delete*' => Http::response(['deleted' => true], 200)]);

    $parent = gsService($site, 'Basement Waterproofing');
    Content::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Hub,
        'status' => ContentStatus::Published, 'title' => 'Basement Waterproofing', 'slug' => 'basement-waterproofing',
        'version' => 1, 'primary_service_id' => $parent->id,
    ]);
    $child = gsService($site, 'Sump Pump', ['parent_service_id' => $parent->id, 'page_treatment' => ServicePageTreatment::Page]);
    $childPage = Content::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'status' => ContentStatus::Published, 'title' => 'Sump Pump', 'slug' => 'sump-pump',
        'version' => 1, 'primary_service_id' => $child->id, 'wp_post_id' => 55,
    ]);

    Livewire::test(ServicesStep::class)->call('setTreatment', $child->id, 'section');

    expect($child->fresh()->page_treatment)->toBe(ServicePageTreatment::Section)
        ->and(Content::withoutGlobalScope(SiteScope::class)->find($childPage->id))->toBeNull() // soft-deleted
        ->and(Redirect::withoutGlobalScope(SiteScope::class)
            ->where('from_url', '/sump-pump')->where('to_url', '/basement-waterproofing')->where('code', 301)->exists())->toBeTrue();
});

it('renders the tree with the "becomes" summary and the sub-service toggle', function () {
    $site = groupingSite();
    $hub = gsService($site, 'Basement Waterproofing');
    gsService($site, 'Sump Pump', ['parent_service_id' => $hub->id, 'page_treatment' => ServicePageTreatment::Page]);

    Livewire::test(ServicesStep::class)
        ->assertOk()
        ->assertSee('Basement Waterproofing')
        ->assertSee('Sump Pump')
        ->assertSee('becomes: Hub')
        ->assertSee('Its own page');
});
