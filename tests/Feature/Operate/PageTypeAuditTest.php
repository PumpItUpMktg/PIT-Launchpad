<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\StandardPageType;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use App\Operate\PageTypeAudit;

function auditPage(Site $site, array $attrs): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'status' => ContentStatus::Published,
    ], $attrs));
}

it('flags a Utility page that carries service signals as misfiled into Core', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);

    // The bug: a service spoke stuck at page_type Utility with a silo pin and no standard_type.
    $bad = auditPage($site, ['page_type' => PageType::Utility, 'silo_id' => $silo->id, 'title' => 'Sump Pump Maintenance', 'slug' => 'spm']);
    // A legitimate standard page — Utility WITH a real standard_type, no service signals.
    $good = auditPage($site, ['page_type' => PageType::Utility, 'standard_type' => StandardPageType::About, 'title' => 'About Us', 'slug' => 'about']);
    // A correctly-typed service page.
    $svc = auditPage($site, ['page_type' => PageType::Service, 'silo_id' => $silo->id, 'title' => 'Sump Pump Repair', 'slug' => 'spr']);

    $result = app(PageTypeAudit::class)->audit($site);
    $byId = collect($result['rows'])->keyBy('id');

    expect($result['flagged'])->toBe(1)
        ->and($byId[$bad->id]['flag'])->toBe('misfiled_core')
        ->and($byId[$bad->id]['suggested'])->toBe('service')
        ->and($byId[$good->id]['flag'])->toBe('ok')      // legit standard page
        ->and($byId[$svc->id]['flag'])->toBe('ok');      // already correct
});

it('suggests Hub for a misfiled page that is a silo pillar', function () {
    $site = Site::factory()->create();
    $pillar = auditPage($site, ['page_type' => PageType::Utility, 'title' => 'Sump Pumps', 'slug' => 'sump-pumps', 'target_keyword_id' => null]);
    // Give it a service signal so it flags, and make it a silo's pillar so the suggestion is Hub.
    $silo = Silo::factory()->create(['site_id' => $site->id, 'pillar_content_id' => $pillar->id]);
    $pillar->forceFill(['silo_id' => $silo->id])->save();

    $byId = collect(app(PageTypeAudit::class)->audit($site)['rows'])->keyBy('id');

    expect($byId[$pillar->id]['flag'])->toBe('misfiled_core')
        ->and($byId[$pillar->id]['suggested'])->toBe('hub');
});

it('flags a null page_type page as invisible', function () {
    $site = Site::factory()->create();
    $ghost = auditPage($site, ['page_type' => null, 'title' => 'Ghost page', 'slug' => 'ghost']);

    $byId = collect(app(PageTypeAudit::class)->audit($site)['rows'])->keyBy('id');

    expect($byId[$ghost->id]['flag'])->toBe('invisible')
        ->and(app(PageTypeAudit::class)->audit($site)['invisible'])->toBe(1);
});

it('repair re-points misfiled Core pages to Service and clears the stray standard_type', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $bad = auditPage($site, [
        'page_type' => PageType::Utility, 'standard_type' => StandardPageType::Faq, // stray
        'silo_id' => $silo->id, 'title' => 'Backup Sump Pump Solutions', 'slug' => 'bsps',
    ]);
    $good = auditPage($site, ['page_type' => PageType::Utility, 'standard_type' => StandardPageType::About, 'title' => 'About', 'slug' => 'about']);

    $r = app(PageTypeAudit::class)->repair($site);

    expect($r['fixed'])->toBe(1)
        ->and($bad->fresh()->page_type)->toBe(PageType::Service)
        ->and($bad->fresh()->standard_type)->toBeNull()
        ->and($good->fresh()->page_type)->toBe(PageType::Utility);   // legit standard page untouched
});

it('is idempotent — a second repair finds nothing to fix', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    auditPage($site, ['page_type' => PageType::Utility, 'silo_id' => $silo->id, 'title' => 'X', 'slug' => 'x']);

    app(PageTypeAudit::class)->repair($site);

    expect(app(PageTypeAudit::class)->repair($site)['fixed'])->toBe(0);
});

it('the audit-page-types command reports and, with --fix, repairs', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $bad = auditPage($site, ['page_type' => PageType::Utility, 'silo_id' => $silo->id, 'title' => 'Sump Pump Maintenance', 'slug' => 'spm']);

    $this->artisan('launchpad:audit-page-types', ['site' => $site->id])
        ->expectsOutputToContain('1 misfiled in Core')
        ->assertSuccessful();

    $this->artisan('launchpad:audit-page-types', ['site' => $site->id, '--fix' => true])
        ->assertSuccessful();

    expect($bad->fresh()->page_type)->toBe(PageType::Service);
});
