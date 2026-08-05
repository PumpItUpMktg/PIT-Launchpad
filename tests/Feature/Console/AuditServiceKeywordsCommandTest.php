<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\KeywordSource;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;

function auditServicePage(Site $site, array $overrides): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'status' => ContentStatus::Published,
        'page_type' => PageType::Service,
    ], $overrides));
}

it('grades each service page by how its target keyword is used, and flags missing targets', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);

    // Optimized: keyword exact in slug, SEO title, and H1.
    $kw1 = Keyword::create(['site_id' => $site->id, 'query' => 'sump pump installation', 'source' => KeywordSource::Seed, 'status' => 'candidate']);
    auditServicePage($site, [
        'slug' => 'sump-pump-installation',
        'title' => 'Sump Pump Installation',
        'target_keyword_id' => $kw1->id,
        'meta' => ['seo' => ['title' => 'Sump Pump Installation', 'meta_description' => 'Expert sump pump installation for a dry basement.']],
        'slot_payload' => ['hero_headline' => 'Sump Pump Installation Done Right', 'svc_intro' => 'Our sump pump installation keeps water out.'],
    ]);

    // Off-target: keyword nowhere in the SEO title or H1 (copy drifted).
    $kw2 = Keyword::create(['site_id' => $site->id, 'query' => 'battery backup sump pump', 'source' => KeywordSource::Seed, 'status' => 'candidate']);
    auditServicePage($site, [
        'slug' => 'backup-power-options',
        'title' => 'Backup Power Options',
        'target_keyword_id' => $kw2->id,
        'meta' => ['seo' => ['title' => 'Backup Power Options', 'meta_description' => 'Keep your home protected.']],
        'slot_payload' => ['hero_headline' => 'Stay Protected in a Storm', 'svc_intro' => 'We install reliable equipment.'],
    ]);

    // No target keyword set at all.
    auditServicePage($site, ['slug' => 'general-services', 'title' => 'General Services', 'target_keyword_id' => null]);

    $code = Artisan::call('launchpad:audit-service-keywords', ['--site' => 'SPG']);
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and($out)->toContain('optimized')
        ->and($out)->toContain('/sump-pump-installation')       // graded optimized
        ->and($out)->toContain('off-target')
        ->and($out)->toContain('/backup-power-options')          // keyword absent from title + H1
        ->and($out)->toContain('— none —')                       // the page with no target keyword
        ->and($out)->toContain('1 optimized, 0 partial, 1 off-target, 1 without a target keyword');
});

it('errors for an unknown site', function () {
    expect(Artisan::call('launchpad:audit-service-keywords', ['--site' => 'Nope Inc']))->toBe(1);
});
