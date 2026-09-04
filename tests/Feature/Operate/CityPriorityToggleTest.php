<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\KeywordSource;
use App\Enums\MarketTier;
use App\Enums\PageType;
use App\Enums\ServiceSiloRole;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateLocationPages;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

function cptCityPage(Site $site, Market $market): Content
{
    return Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page->value, 'page_type' => PageType::Location->value,
        'status' => ContentStatus::Published->value, 'market_id' => $market->id,
        'title' => $market->name, 'slug' => Str::slug($market->name),
    ]);
}

it('promotes a city to priority from the card and assigns its tracking keywords', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    session(['guided_site_id' => $site->id]); // the locked working tenant (the gate/switcher sets this in prod)
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Repair', 'silo_role' => ServiceSiloRole::Pillar->value]);
    $market = Market::factory()->create(['site_id' => $site->id, 'name' => 'Norristown', 'tier' => MarketTier::Coverage->value]);
    $page = cptCityPage($site, $market);

    Livewire::test(OperateLocationPages::class)->call('toggleCityPriority', $page->id);

    expect($market->fresh()->tier)->toBe(MarketTier::Priority)
        ->and(Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('source', KeywordSource::Local->value)->count())->toBe(2);
});

it('demotes back to coverage and prunes the city keywords', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    session(['guided_site_id' => $site->id]); // the locked working tenant (the gate/switcher sets this in prod)
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Repair', 'silo_role' => ServiceSiloRole::Pillar->value]);
    $market = Market::factory()->create(['site_id' => $site->id, 'name' => 'Norristown', 'tier' => MarketTier::Priority->value]);
    $page = cptCityPage($site, $market);

    $board = Livewire::test(OperateLocationPages::class);
    $board->call('toggleCityPriority', $page->id); // priority already → this demotes to coverage

    expect($market->fresh()->tier)->toBe(MarketTier::Coverage)
        ->and(Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('source', KeywordSource::Local->value)->count())->toBe(0)
        // The FK nulls the page's headline pointer when its keyword is pruned.
        ->and($page->fresh()->target_keyword_id)->toBeNull();
});

it('is a no-op for a page with no market (service/core pages)', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    session(['guided_site_id' => $site->id]); // the locked working tenant (the gate/switcher sets this in prod)
    $page = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page->value, 'page_type' => PageType::Service->value,
        'status' => ContentStatus::Published->value, 'market_id' => null, 'title' => 'Sump Pump Repair', 'slug' => 'sump-pump-repair',
    ]);

    Livewire::test(OperateLocationPages::class)->call('toggleCityPriority', $page->id);

    expect(Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});
