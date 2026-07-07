<?php

use App\Enums\ContentKind;
use App\Enums\MarketTier;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\Market;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Publishing\Chrome\SiteProfileAssembler;

it('assembles the site profile from real §1 data — brand, NAP, real page links, priority areas', function () {
    $site = Site::factory()->create([
        'brand_name' => 'Sewer Gurus',
        'domain_url' => 'https://sewergurus.com',
        'offers_emergency' => true,
    ]);
    Location::factory()->create([
        'site_id' => $site->id,
        'phone' => '(973) 555-0100',
        'address' => '10 Main St, Newark, NJ',
    ]);
    // Home page — its hero eyebrow becomes the tagline.
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Home,
        'slug' => 'home', 'title' => 'Home',
        'slot_payload' => ['service_area' => 'Commercial Plumbing · Northern NJ'],
    ]);
    // Real service pages → services + are the only nav links that exist.
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'slug' => 'drain-cleaning', 'title' => 'Drain Cleaning']);
    // An informational page → company/nav link.
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Utility, 'slug' => 'about', 'title' => 'About Us']);
    // Markets — priority first.
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Newark', 'tier' => MarketTier::Coverage]);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Jersey City', 'tier' => MarketTier::Priority]);
    // Uploaded logo → the header serves it from R2.
    SiteBranding::factory()->create(['site_id' => $site->id, 'logo_set' => ['url' => 'https://cdn.example/sites/x/brand-logo.svg']]);

    $profile = app(SiteProfileAssembler::class)->assemble($site->fresh());

    expect($profile['brand_name'])->toBe('Sewer Gurus')
        ->and($profile['logo_url'])->toBe('https://cdn.example/sites/x/brand-logo.svg')
        ->and($profile['tagline'])->toBe('Commercial Plumbing · Northern NJ')
        ->and($profile['phone'])->toBe('(973) 555-0100')
        ->and($profile['phone_tel'])->toBe('tel:9735550100')
        ->and($profile['emergency'])->toBeTrue()
        ->and($profile['address'])->toBe('10 Main St, Newark, NJ')
        ->and($profile['hours'])->toContain('24/7 Emergency')
        // real internal links only
        ->and($profile['services'])->toHaveCount(1)
        ->and($profile['services'][0])->toBe(['label' => 'Drain Cleaning', 'url' => 'https://sewergurus.com/drain-cleaning'])
        ->and($profile['company'][0])->toBe(['label' => 'About Us', 'url' => 'https://sewergurus.com/about'])
        // priority market ordered first
        ->and(array_column($profile['areas'], 'label'))->toBe(['Jersey City', 'Newark']);
});

it('degrades cleanly for a bare site — no phone, no links, chrome falls back to the site title', function () {
    $site = Site::factory()->create(['brand_name' => 'Bare Co', 'domain_url' => 'https://bare.test', 'offers_emergency' => false]);

    $profile = app(SiteProfileAssembler::class)->assemble($site->fresh());

    expect($profile['brand_name'])->toBe('Bare Co')
        ->and($profile['phone'])->toBe('')
        ->and($profile['phone_tel'])->toBe('')
        ->and($profile['services'])->toBe([])
        ->and($profile['areas'])->toBe([])
        ->and($profile['company'])->toBe([])
        ->and($profile['hours'])->toBe('');
});
