<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\MarketTier;
use App\Enums\PageType;
use App\Enums\ServiceSiloRole;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use Illuminate\Support\Str;

it('assigns city keywords for a named site and reports them', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Repair', 'silo_role' => ServiceSiloRole::Pillar->value]);
    $market = Market::factory()->create(['site_id' => $site->id, 'name' => 'Norristown', 'tier' => MarketTier::Priority->value]);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page->value, 'page_type' => PageType::Location->value,
        'status' => ContentStatus::Published->value, 'market_id' => $market->id, 'title' => 'Norristown', 'slug' => Str::slug('Norristown'),
    ]);

    $this->artisan('launchpad:track-city-keywords', ['--site' => 'SPG'])
        ->expectsOutputToContain('Sump Pump Repair Norristown')
        ->assertSuccessful();

    expect(Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(2);
});

it('writes nothing on --dry-run', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Repair', 'silo_role' => ServiceSiloRole::Pillar->value]);
    $market = Market::factory()->create(['site_id' => $site->id, 'name' => 'Norristown', 'tier' => MarketTier::Priority->value]);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page->value, 'page_type' => PageType::Location->value,
        'status' => ContentStatus::Published->value, 'market_id' => $market->id, 'title' => 'Norristown', 'slug' => 'norristown',
    ]);

    $this->artisan('launchpad:track-city-keywords', ['--site' => 'SPG', '--dry-run' => true])->assertSuccessful();

    expect(Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});
