<?php

use App\Audit\AuditReport;
use App\Audit\AuditRunner;
use App\Audit\CheckRegistry;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\SiteStatus;
use App\Models\Content;
use App\Models\Market;
use App\Models\Service;
use App\Models\Site;

/**
 * @param  list<Site>  $sites
 */
function auditReport(array $sites): AuditReport
{
    return app(AuditRunner::class)->run($sites, app(CheckRegistry::class)->all());
}

it('SLOT-001 flags a live service page with no pinned service, and is clean when pinned', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Active, 'domain_url' => 'https://sp.example']);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'status' => ContentStatus::Published, 'wp_post_id' => 10, 'slug' => 'sewer-lines/emergency',
        'title' => 'Emergency Plumbing', 'primary_service_id' => null,
    ]);

    expect(auditReport([$site->fresh()])->findingsFor($site->id, 'SLOT-001'))->toHaveCount(1);

    // Pin it → clean.
    $service = Service::factory()->create(['site_id' => $site->id, 'price_range' => ['low' => 100, 'high' => 500, 'unit' => 'job'], 'symptoms' => null, 'scope_items' => null, 'process_steps' => null, 'cost_factors' => null]);
    Content::withoutGlobalScopes()->where('site_id', $site->id)->update(['primary_service_id' => $service->id]);
    expect(auditReport([$site->fresh()])->findingsFor($site->id, 'SLOT-001'))->toBeEmpty();
});

it('NOINDEX-001 flags a non-Live tenant and clears once Live', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Active]);
    expect(auditReport([$site->fresh()])->findingsFor($site->id, 'NOINDEX-001'))->toHaveCount(1);

    $site->forceFill(['status' => SiteStatus::Live])->save();
    expect(auditReport([$site->fresh()])->findingsFor($site->id, 'NOINDEX-001'))->toBeEmpty();
});

it('NAP-001 flags a tenant carrying the agency address', function () {
    config()->set('launchpad.audit.agency_address', '377 Valley Road, Clifton, NJ 07013');
    $site = Site::factory()->create([
        'corporate_street' => '377 Valley Road', 'corporate_city' => 'Clifton',
        'corporate_state' => 'NJ', 'corporate_postal_code' => '07013',
    ]);

    $findings = auditReport([$site->fresh()])->findingsFor($site->id, 'NAP-001');
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->detail)->toContain('agency address');
});

it('COV-001 flags numbered parse artifacts and duplicate towns', function () {
    $site = Site::factory()->create();
    Market::factory()->create(['site_id' => $site->id, 'name' => '1, Abingdon']);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Bethlehem']);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Bethlehem']); // duplicate

    expect(auditReport([$site->fresh()])->findingsFor($site->id, 'COV-001'))->toHaveCount(2);
});

it('BLOG-001 flags a -N slug suffix and a repeated title', function () {
    $site = Site::factory()->create();
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'wp_post_id' => 1, 'slug' => 'radon-risk', 'title' => 'Radon Risk']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'wp_post_id' => 2, 'slug' => 'radon-risk-2', 'title' => 'Radon Risk']);

    // One -N suffix + one repeated title.
    expect(auditReport([$site->fresh()])->findingsFor($site->id, 'BLOG-001'))->toHaveCount(2);
});

it('a fully clean Live tenant produces no findings and does not trip the gate', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Live, 'domain_url' => 'https://clean.example']);
    $service = Service::factory()->create(['site_id' => $site->id, 'price_range' => ['low' => 100, 'high' => 500, 'unit' => 'job'], 'symptoms' => null, 'scope_items' => null, 'process_steps' => null, 'cost_factors' => null]);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'status' => ContentStatus::Published, 'wp_post_id' => 5, 'slug' => 'drain-cleaning',
        'title' => 'Drain Cleaning', 'primary_service_id' => $service->id,
    ]);

    $report = auditReport([$site->fresh()]);
    expect($report->trips('any'))->toBeFalse()
        ->and($report->worstSeverity())->toBe('');
});

it('the command runs, emits JSON, and gates non-zero on --fail-on', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Active]); // NOINDEX-001 (critical)

    $this->artisan('launchpad:audit', ['--tenant' => $site->id, '--format' => 'json', '--fail-on' => 'critical'])
        ->assertExitCode(1);
});
