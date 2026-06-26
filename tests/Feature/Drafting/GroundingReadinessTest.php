<?php

use App\ContentEngine\Drafting\GroundingReadiness;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Market;
use App\Models\Service;
use App\Models\Site;
use App\Models\SiteBranding;

function readiness(): GroundingReadiness
{
    return app(GroundingReadiness::class);
}

it('grounds a service page only when the site has a resolvable service', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->page()->create(['site_id' => $site->id, 'page_type' => PageType::Service]);

    expect(readiness()->ready($page))->toBeFalse(); // no §1 Service yet → grounding pending

    Service::factory()->create(['site_id' => $site->id]);

    expect(readiness()->ready($page->fresh()))->toBeTrue();
});

it('grounds a location page only when the site has a market', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->page()->create(['site_id' => $site->id, 'page_type' => PageType::Location]);

    expect(readiness()->ready($page))->toBeFalse();

    Market::factory()->create(['site_id' => $site->id]);

    expect(readiness()->ready($page->fresh()))->toBeTrue();
});

it('grounds a hub (category) page on its silo services', function () {
    $site = Site::factory()->create();
    Service::factory()->create(['site_id' => $site->id]);

    $hub = Content::factory()->page()->create(['site_id' => $site->id, 'page_type' => PageType::Hub]);
    expect(readiness()->ready($hub))->toBeTrue();
});

it('grounds home/utility brand-narrative pages on the brand — services or branding, not a bare site', function () {
    // a bare site (no branding, no services) → a brand-narrative page can't ground yet
    $bare = Site::factory()->create();
    foreach ([PageType::Home, PageType::Utility] as $type) {
        $page = Content::factory()->page()->create(['site_id' => $bare->id, 'page_type' => $type]);
        expect(readiness()->ready($page))->toBeFalse();
    }

    // once the site has real services, the brand-narrative pages ground
    $grounded = Site::factory()->create();
    Service::factory()->create(['site_id' => $grounded->id]);
    foreach ([PageType::Home, PageType::Utility] as $type) {
        $page = Content::factory()->page()->create(['site_id' => $grounded->id, 'page_type' => $type]);
        expect(readiness()->ready($page))->toBeTrue();
    }

    // branding alone is also enough (no services needed)
    $branded = Site::factory()->create();
    SiteBranding::factory()->create(['site_id' => $branded->id]);
    $home = Content::factory()->page()->create(['site_id' => $branded->id, 'page_type' => PageType::Home]);
    expect(readiness()->ready($home))->toBeTrue();
});
