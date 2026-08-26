<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Site;

function covReportLocationPage(Site $site, string $title, string $slug): void
{
    Content::create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'title' => $title, 'slug' => $slug, 'version' => 1,
    ]);
}

it('reports served towns vs the ones with a location page, and names the missing', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Haverford']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Ardmore']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Marple']);   // no page

    // Pages titled "{City}, {ST}" — the format that must still match the bare coverage name.
    covReportLocationPage($site, 'Haverford, PA', 'haverford-pa');
    covReportLocationPage($site, 'Ardmore, PA', 'ardmore-pa');

    $this->artisan('launchpad:coverage-page-report', ['site' => 'Sump Pump Gurus'])
        ->expectsOutputToContain('Towns missing a page')
        ->expectsOutputToContain('Marple')      // the one without a page is named
        ->assertExitCode(0);
});

it('--missing lists only the towns without a page', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Haverford']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Marple']);
    covReportLocationPage($site, 'Haverford, PA', 'haverford-pa');

    $this->artisan('launchpad:coverage-page-report', ['site' => $site->id, '--missing' => true])
        ->expectsOutput('Marple')
        ->doesntExpectOutput('Haverford')
        ->assertExitCode(0);
});

it('confirms full coverage when every town has a page', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Haverford']);
    covReportLocationPage($site, 'Haverford, PA', 'haverford-pa');

    $this->artisan('launchpad:coverage-page-report', ['site' => $site->id])
        ->expectsOutputToContain('Every served town has its own location page')
        ->assertExitCode(0);
});

it('fails clearly when no site matches', function () {
    $this->artisan('launchpad:coverage-page-report', ['site' => 'nope-nothing'])
        ->expectsOutputToContain('No site matches')
        ->assertExitCode(1);
});
