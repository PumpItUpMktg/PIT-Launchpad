<?php

use App\Enums\PageType;
use App\Enums\StandardPageType;
use App\Models\Content;
use App\Models\Site;
use App\Models\WireframeKit;
use Database\Seeders\WireframeKitSeeder;

beforeEach(function () {
    (new WireframeKitSeeder)->run();
    $this->site = Site::factory()->create(['brand_name' => 'Sewer Gurus']);
});

/** A standard page materialized before its kit existed → wireframe_kit_id is null. */
function kitlessPage(string $siteId, StandardPageType $type, PageType $pageType, string $slug): Content
{
    return Content::factory()->page()->create([
        'site_id' => $siteId,
        'page_type' => $pageType,
        'standard_type' => $type->value,
        'wireframe_kit_id' => null,
        'wireframe_kit_version' => null,
        'slug' => $slug,
    ]);
}

it('reports kit-less pages without writing in dry-run', function () {
    $page = kitlessPage($this->site->id, StandardPageType::Faq, PageType::Utility, 'faq');

    $this->artisan('launchpad:relink-page-kits', ['site' => $this->site->id])
        ->expectsOutputToContain('would link faq-page')
        ->assertSuccessful();

    expect($page->fresh()->wireframe_kit_id)->toBeNull(); // dry-run wrote nothing
});

it('relinks kit-less standard pages by standard_type on --apply', function () {
    $faq = kitlessPage($this->site->id, StandardPageType::Faq, PageType::Utility, 'faq');
    $about = kitlessPage($this->site->id, StandardPageType::About, PageType::Utility, 'about');

    $this->artisan('launchpad:relink-page-kits', ['site' => 'Sewer Gurus', '--apply' => true])
        ->assertSuccessful();

    $faqKit = WireframeKit::where('name', 'faq-page')->whereNull('site_id')->firstOrFail();
    $aboutKit = WireframeKit::where('name', 'about-page')->whereNull('site_id')->firstOrFail();

    expect($faq->fresh()->wireframe_kit_id)->toBe($faqKit->id)
        ->and($faq->fresh()->wireframe_kit_version)->toBe($faqKit->version)
        ->and($about->fresh()->wireframe_kit_id)->toBe($aboutKit->id);
});

it('never overwrites a page that already has a kit', function () {
    $kit = WireframeKit::where('name', 'about-page')->whereNull('site_id')->firstOrFail();
    $page = Content::factory()->page()->create([
        'site_id' => $this->site->id,
        'page_type' => PageType::Utility,
        'standard_type' => StandardPageType::About->value,
        'wireframe_kit_id' => $kit->id,
        'wireframe_kit_version' => 99, // deliberately odd — must be left untouched
        'slug' => 'about',
    ]);

    $this->artisan('launchpad:relink-page-kits', ['site' => $this->site->id, '--apply' => true])
        ->expectsOutputToContain('All pages already carry a wireframe kit')
        ->assertSuccessful();

    expect($page->fresh()->wireframe_kit_version)->toBe(99); // not clobbered
});

it('leaves a page whose kit has no composer yet untouched', function () {
    // Gallery has no shipped kit (StandardKit::resolve → null).
    $page = kitlessPage($this->site->id, StandardPageType::Gallery, PageType::Utility, 'gallery');

    $this->artisan('launchpad:relink-page-kits', ['site' => $this->site->id, '--apply' => true])
        ->expectsOutputToContain('composer hasn\'t shipped')
        ->assertSuccessful();

    expect($page->fresh()->wireframe_kit_id)->toBeNull();
});

it('fails cleanly on an unknown site', function () {
    $this->artisan('launchpad:relink-page-kits', ['site' => 'No Such Tenant'])
        ->expectsOutputToContain('Site not found')
        ->assertFailed();
});
