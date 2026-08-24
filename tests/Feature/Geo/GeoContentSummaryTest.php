<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Geo\GeoContentSummary;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Str;

afterEach(fn () => CurrentSite::clear());

function geoContent(Site $site, ?Silo $silo, string $status, string $lane = Content::GEO_LANE): Content
{
    return Content::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id,
        'silo_id' => $silo?->id,
        'kind' => ContentKind::Post,
        'status' => $status,
        'title' => 'x',
        'slug' => 'x-'.Str::random(6),
        'draft_lane' => $lane,
        'version' => 1,
    ]);
}

it('counts published GEO content per silo, most-published first', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $repair = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $install = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Install']);

    geoContent($site, $repair, ContentStatus::Published->value);
    geoContent($site, $repair, ContentStatus::Published->value);
    geoContent($site, $install, ContentStatus::Published->value);
    // Not counted: not published, and not the GEO lane.
    geoContent($site, $repair, ContentStatus::NeedsReview->value);
    geoContent($site, $repair, ContentStatus::Published->value, lane: 'reactive');

    $rows = (new GeoContentSummary)->publishedBySilo();

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray(['silo' => 'Repair', 'tenant' => 'Sump Pump Gurus', 'published' => 2])
        ->and($rows[1])->toMatchArray(['silo' => 'Install', 'published' => 1]);
});

it('rolls unrouted published content under Uncategorized', function () {
    $site = Site::factory()->create();
    geoContent($site, null, ContentStatus::Published->value);

    $rows = (new GeoContentSummary)->publishedBySilo();

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['silo_id' => null, 'silo' => 'Uncategorized', 'published' => 1]);
});

it('rolls up lane pipeline counts', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);

    geoContent($site, $silo, ContentStatus::Candidate->value);
    geoContent($site, $silo, ContentStatus::Scored->value);
    geoContent($site, $silo, ContentStatus::NeedsReview->value);
    geoContent($site, $silo, ContentStatus::RenderFailed->value);
    geoContent($site, $silo, ContentStatus::Published->value);
    geoContent($site, $silo, ContentStatus::Published->value, lane: 'reactive'); // not GEO → excluded

    expect((new GeoContentSummary)->laneCounts())
        ->toMatchArray(['candidates' => 2, 'in_review' => 2, 'published' => 1]);
});
