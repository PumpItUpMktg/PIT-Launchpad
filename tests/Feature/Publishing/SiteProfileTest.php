<?php

use App\Enums\ContentKind;
use App\Enums\MarketTier;
use App\Enums\PageType;
use App\Enums\ServiceSiloRole;
use App\Models\Content;
use App\Models\Location;
use App\Models\Market;
use App\Models\Service;
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
    // Uploaded logo → the header serves it from R2, and carries the chosen header tone.
    SiteBranding::factory()->create(['site_id' => $site->id, 'logo_set' => ['url' => 'https://cdn.example/sites/x/brand-logo.svg', 'header_tone' => 'dark']]);

    $profile = app(SiteProfileAssembler::class)->assemble($site->fresh());

    expect($profile['brand_name'])->toBe('Sewer Gurus')
        ->and($profile['logo_url'])->toBe('https://cdn.example/sites/x/brand-logo.svg')
        ->and($profile['header_tone'])->toBe('dark')
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

it('caps the header services at 8 and ranks by importance (hub → pillar → supporting → guide last)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sg.test']);
    $pillar = Service::factory()->create(['site_id' => $site->id, 'silo_role' => ServiceSiloRole::Pillar]);
    $supporting = Service::factory()->create(['site_id' => $site->id, 'silo_role' => ServiceSiloRole::Supporting]);

    $page = function (string $slug, PageType $type, ?string $serviceId, int $ageDays) use ($site): void {
        Content::factory()->create([
            'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => $type,
            'slug' => $slug, 'title' => ucwords(str_replace('-', ' ', $slug)),
            'primary_service_id' => $serviceId, 'created_at' => now()->subDays($ageDays),
        ]);
    };

    $page('our-services', PageType::Hub, null, 100);                       // hub → ranks first
    foreach (['sump-pump-installation', 'sump-pump-replacement', 'foundation-water',
        'sewage-ejector', 'waterproofing', 'crawlspace'] as $i => $slug) {
        $page($slug, PageType::Service, $pillar->id, 90 - $i);             // 6 core (pillar) services
    }
    $page('exterior-drainage', PageType::Service, $supporting->id, 30);    // supporting service
    $page('cost-guide', PageType::Service, null, 20);                      // guide, no core link → last

    $labels = array_column(app(SiteProfileAssembler::class)->assemble($site->fresh())['services'], 'label');

    expect($labels)->toHaveCount(8)                 // capped (9 pages exist)
        ->and($labels[0])->toBe('Our Services')     // the hub leads
        ->and($labels)->toContain('Exterior Drainage')  // supporting still made the cut
        ->and($labels)->not->toContain('Cost Guide');   // the guide sank past the cap
});

it('uses the automatic top-8 service ranking when no page is featured', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    // 9 service pages, no featured → capped at 8, hub first.
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Hub, 'slug' => 'services', 'title' => 'Our Services']);
    foreach (range(1, 9) as $n) {
        Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'slug' => "svc-{$n}", 'title' => "Service {$n}"]);
    }

    $services = app(SiteProfileAssembler::class)->assemble($site->fresh())['services'];
    expect($services)->toHaveCount(8)
        ->and($services[0]['label'])->toBe('Our Services'); // hub ranks first
});

it('shows exactly the operator-featured pages, in manual order, uncapped', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    // 10 service pages exist, but the operator features 3 (a subset) in an explicit order.
    foreach (range(1, 10) as $n) {
        Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'slug' => "svc-{$n}", 'title' => "Service {$n}"]);
    }
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'slug' => 'a', 'title' => 'Alpha', 'nav_featured' => true, 'nav_order' => 3]);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'slug' => 'b', 'title' => 'Bravo', 'nav_featured' => true, 'nav_order' => 1]);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'slug' => 'c', 'title' => 'Charlie', 'nav_featured' => true, 'nav_order' => 2]);

    $services = app(SiteProfileAssembler::class)->assemble($site->fresh())['services'];

    // Exactly the 3 featured, ordered by nav_order — NOT the automatic 8.
    expect(array_column($services, 'label'))->toBe(['Bravo', 'Charlie', 'Alpha']);
});

it('can feature MORE than the automatic cap of 8 (operator decides the count)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    foreach (range(1, 11) as $n) {
        Content::factory()->create([
            'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
            'slug' => "svc-{$n}", 'title' => "Service {$n}", 'nav_featured' => true, 'nav_order' => $n,
        ]);
    }

    expect(app(SiteProfileAssembler::class)->assemble($site->fresh())['services'])->toHaveCount(11);
});

it('never lists a featured page in both the services and company menus', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    // The About page is a company link by slug — but the operator pins it into the header.
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Utility, 'slug' => 'about', 'title' => 'About Us', 'nav_featured' => true, 'nav_order' => 1]);

    $profile = app(SiteProfileAssembler::class)->assemble($site->fresh());

    expect(array_column($profile['services'], 'label'))->toContain('About Us')
        ->and(array_column($profile['company'], 'label'))->not->toContain('About Us')  // deduped
        ->and(array_column($profile['nav'], 'label'))->not->toContain('About Us');
});

it('lets an operator override the header tone regardless of the logo', function () {
    $site = Site::factory()->create();
    // A logo that reads as dark, so the override has something to win over.
    SiteBranding::factory()->create(['site_id' => $site->id, 'logo_set' => ['url' => 'https://cdn.example/x.png', 'header_tone' => 'dark']]);

    // No override → the logo-derived dark tone.
    expect(app(SiteProfileAssembler::class)->assemble($site->fresh())['header_tone'])->toBe('dark');

    // Operator forces light → the override wins.
    $site->forceFill(['header_tone_override' => 'light'])->save();
    expect(app(SiteProfileAssembler::class)->assemble($site->fresh())['header_tone'])->toBe('light');

    // Operator forces dark on a no-logo site → dark, over the light default.
    $bare = Site::factory()->create(['header_tone_override' => 'dark']);
    expect(app(SiteProfileAssembler::class)->assemble($bare->fresh())['header_tone'])->toBe('dark');
});

it('degrades cleanly for a bare site — no phone, no links, chrome falls back to the site title', function () {
    $site = Site::factory()->create(['brand_name' => 'Bare Co', 'domain_url' => 'https://bare.test', 'offers_emergency' => false]);

    $profile = app(SiteProfileAssembler::class)->assemble($site->fresh());

    expect($profile['brand_name'])->toBe('Bare Co')
        ->and($profile['phone'])->toBe('')
        ->and($profile['phone_tel'])->toBe('')
        ->and($profile['header_tone'])->toBe('light') // no logo → the clean default bar (matches the plugin's render fallback)
        ->and($profile['services'])->toBe([])
        ->and($profile['areas'])->toBe([])
        ->and($profile['company'])->toBe([])
        ->and($profile['hours'])->toBe('');
});

it('puts Areas We Serve in the header nav and Privacy/Terms in the footer legal links — real pages only', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Utility, 'slug' => 'about', 'title' => 'About Us']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Utility, 'slug' => 'areas-we-serve', 'title' => 'Areas We Serve']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Utility, 'slug' => 'privacy-policy', 'title' => 'Privacy Policy']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Utility, 'slug' => 'terms-of-service', 'title' => 'Terms of Service']);

    $profile = app(SiteProfileAssembler::class)->assemble($site->fresh());

    // Header main menu: company pages + Areas We Serve; legal pages stay OUT of the header.
    $navLabels = array_column($profile['nav'], 'label');
    expect($navLabels)->toContain('About Us')->toContain('Areas We Serve')
        ->not->toContain('Privacy Policy')->not->toContain('Terms of Service');
    expect(collect($profile['nav'])->firstWhere('label', 'Areas We Serve')['url'])
        ->toBe('https://sewergurus.com/areas-we-serve');

    // Footer legal links: privacy + terms, real URLs.
    expect(array_column($profile['legal_links'], 'label'))->toBe(['Privacy Policy', 'Terms of Service'])
        ->and($profile['legal_links'][0]['url'])->toBe('https://sewergurus.com/privacy-policy');

    // A site without those pages advertises nothing (never a dead link).
    $bare = Site::factory()->create(['domain_url' => 'https://bare.example']);
    $profile = app(SiteProfileAssembler::class)->assemble($bare->fresh());
    expect($profile['legal_links'])->toBe([])
        ->and(array_column($profile['nav'], 'label'))->not->toContain('Areas We Serve');
});
