<?php

use App\ContentEngine\Drafting\GroundingReadiness;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Market;
use App\Models\Service;
use App\Models\Site;

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

it('never grounds home/hub/utility pages (no entity grounding wired)', function () {
    $site = Site::factory()->create();
    Service::factory()->create(['site_id' => $site->id]);
    Market::factory()->create(['site_id' => $site->id]);

    foreach ([PageType::Home, PageType::Hub, PageType::Utility] as $type) {
        $page = Content::factory()->page()->create(['site_id' => $site->id, 'page_type' => $type]);
        expect(readiness()->ready($page))->toBeFalse();
    }
});
