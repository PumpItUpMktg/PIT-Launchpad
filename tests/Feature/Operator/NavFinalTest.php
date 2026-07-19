<?php

use App\Enums\UserRole;
use App\Filament\Pages\Gathering\BusinessStep;
use App\Filament\Pages\Gathering\SetupEntry;
use App\Filament\Pages\MenuMapPage;
use App\Filament\Pages\NewMenuPage;
use App\Filament\Pages\Operate\HeaderMenu;
use App\Filament\Pages\Operate\OperateBlog;
use App\Filament\Pages\Operate\OperateCorePages;
use App\Filament\Pages\Operate\OperateDashboard;
use App\Filament\Pages\Operate\OperateLocationPages;
use App\Filament\Pages\Operate\OperatePhysicalLocations;
use App\Filament\Pages\Operate\OperateServicePages;
use App\Filament\Pages\Operate\OrphanScan;
use App\Filament\Pages\SiteCockpit;
use App\Filament\Resources\ContentEditResource;
use App\Filament\Resources\SiteResource;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    // Nav-final is the cutover end-state: both parallel-build flags on.
    config()->set('launchpad.new_setup_enabled', true);
    config()->set('launchpad.new_operate_enabled', true);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
});

it('the three top-level entries are Dashboard, Portfolio, Setup — each ungrouped and registered', function () {
    // Dashboard(root) · Portfolio · Setup: the only top-level (ungrouped) sidebar items.
    expect(OperateDashboard::shouldRegisterNavigation())->toBeTrue()
        ->and(OperateDashboard::getNavigationGroup())->toBeNull()
        ->and(OperateDashboard::getNavigationLabel())->toBe('Dashboard')
        ->and(SiteResource::shouldRegisterNavigation())->toBeTrue()
        ->and(SiteResource::getNavigationGroup())->toBeNull()
        ->and(SiteResource::getNavigationLabel())->toBe('Portfolio')
        ->and(SetupEntry::shouldRegisterNavigation())->toBeTrue()
        ->and(SetupEntry::getNavigationGroup())->toBeNull()
        ->and(SetupEntry::getNavigationLabel())->toBe('Setup');
});

it('the Operate group is exactly the five pages boards — Blog, Core, Service, Location, Physical', function () {
    $boards = [
        OperateBlog::class => 'Blog',
        OperateCorePages::class => 'Core pages',
        OperateServicePages::class => 'Service pages',
        OperateLocationPages::class => 'Location pages',
        OperatePhysicalLocations::class => 'Locations',
    ];
    foreach ($boards as $class => $label) {
        expect($class::shouldRegisterNavigation())->toBeTrue()
            ->and($class::getNavigationGroup())->toBe('Operate')
            ->and($class::getNavigationLabel())->toBe($label);
    }
});

it('the setup step pages and the build-time worksheets no longer register — nothing else in the sidebar', function () {
    // Steps ride the in-page rail; the worksheets and the raw-signal editor are deep-link only.
    expect(BusinessStep::shouldRegisterNavigation())->toBeFalse()
        ->and(MenuMapPage::shouldRegisterNavigation())->toBeFalse()
        ->and(NewMenuPage::shouldRegisterNavigation())->toBeFalse()
        ->and(ContentEditResource::shouldRegisterNavigation())->toBeFalse();
});

it('the cockpit Manage card is the single home for the eight config surfaces that left the sidebar', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    session(['cockpit_site_id' => $site->id]);

    $links = Livewire::test(SiteCockpit::class)->instance()->getManageLinksProperty();
    $labels = collect($links)->pluck('label');

    // Every settings-orphan surface has exactly one entry point here (closes the URL-only gap).
    expect($labels)->toContain('Services', 'Service area', 'Locations', 'Voice', 'Feeds', 'Connections', 'Header menu', 'Orphans')
        ->and($labels)->toHaveCount(8)
        // Each link resolves to a real URL — no dead entries.
        ->and(collect($links)->every(fn (array $l) => filled($l['url'])))->toBeTrue();
});

it('the Manage card renders on the cockpit, each surface reachable from one place', function () {
    $site = Site::factory()->create(['brand_name' => 'Basement Guard']);
    session(['cockpit_site_id' => $site->id]);

    Livewire::test(SiteCockpit::class)
        ->assertSee('Manage Basement Guard')
        ->assertSee('Services')
        ->assertSee('Connections')
        ->assertSee('Orphans');
});

it('the off-menu surfaces stay fully routable — hidden from nav is not removed', function () {
    // Nav-final hides them from the sidebar but the routes live on (reached via the Manage card).
    $site = Site::factory()->create();

    expect(HeaderMenu::getUrl(['site' => $site->id]))->toContain('operate/header-menu')
        ->and(OrphanScan::getUrl(['site' => $site->id]))->toContain('operate/orphans')
        ->and(MenuMapPage::getUrl())->toContain('menu-map');
});
