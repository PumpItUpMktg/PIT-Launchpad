<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Locations\LegacyRedirectAuditor;
use App\Models\Content;
use App\Models\Location;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

function locationPage(Site $site, string $title, string $slug, array $extra = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Published, 'wp_post_id' => random_int(100, 99999),
        'title' => $title, 'slug' => $slug,
    ], $extra));
}

it('proposes the bare-town legacy redirect for each location page', function () {
    $site = Site::factory()->create();
    locationPage($site, 'Hoboken, NJ', 'hoboken-nj');

    $r = app(LegacyRedirectAuditor::class)->audit($site, apply: false);

    expect($r['create'])->toContain(['from' => '/hoboken', 'to' => '/hoboken-nj'])
        ->and(Redirect::withoutGlobalScope(SiteScope::class)->count())->toBe(0); // preview writes nothing
});

it('repoints a legacy redirect whose target drifted to a blog post', function () {
    $site = Site::factory()->create();
    locationPage($site, 'Hoboken, NJ', 'hoboken-nj');
    Redirect::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'from_url' => '/hoboken', 'to_url' => '/hoboken-flood-milestone-blog-post',
        'code' => 301, 'status' => 'active', 'source' => 'migration',
    ]);

    $r = app(LegacyRedirectAuditor::class)->audit($site, apply: true);

    expect($r['fix'])->toContain(['from' => '/hoboken', 'to' => '/hoboken-nj', 'was' => '/hoboken-flood-milestone-blog-post']);
    $fixed = Redirect::withoutGlobalScope(SiteScope::class)->where('from_url', '/hoboken')->first();
    expect($fixed->to_url)->toBe('/hoboken-nj'); // the stale blog target is corrected
});

it('never shadows a live page that owns the bare slug', function () {
    $site = Site::factory()->create();
    locationPage($site, 'Hoboken', 'hoboken'); // canonical IS the bare slug — no legacy variant

    $r = app(LegacyRedirectAuditor::class)->audit($site, apply: false);

    expect($r['create'])->toBe([])->and($r['fix'])->toBe([]);
});

it('leaves a correct existing redirect untouched', function () {
    $site = Site::factory()->create();
    locationPage($site, 'Trooper, PA', 'trooper-pa');
    Redirect::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'from_url' => '/trooper', 'to_url' => '/trooper-pa',
        'code' => 301, 'status' => 'active', 'source' => 'migration',
    ]);

    $r = app(LegacyRedirectAuditor::class)->audit($site, apply: true);

    expect($r['ok'])->toContain(['from' => '/trooper', 'to' => '/trooper-pa'])
        ->and($r['create'])->toBe([])
        ->and($r['fix'])->toBe([]);
});

it('targets only live pages — an unpublished town page produces no redirect', function () {
    $site = Site::factory()->create();
    locationPage($site, 'Queens, NY', 'roslyn-ny/queens-ny', ['status' => ContentStatus::NeedsReview, 'wp_post_id' => null]);
    locationPage($site, 'Trooper, PA', 'trooper-pa');

    $r = app(LegacyRedirectAuditor::class)->audit($site, apply: false);

    expect(array_column($r['create'], 'from'))->toBe(['/trooper']);
});

it('settles a shared bare slug on the office landing, and surfaces it as ambiguous when no landing claims it', function () {
    $site = Site::factory()->create();
    $office = Location::factory()->create(['site_id' => $site->id, 'name' => 'Bedminster office']);
    // The Bedminster NJ office landing and a Bedminster PA town page both claim /bedminster.
    locationPage($site, 'Bedminster, NJ', 'bedminster-nj', ['location_id' => $office->id]);
    locationPage($site, 'Bedminster, PA', 'doylestown-pa/bedminster-pa');
    // Two Middletown TOWN pages, neither an office: undecidable.
    locationPage($site, 'Middletown, NJ', 'new-brunswick-nj/middletown-nj');
    locationPage($site, 'Middletown, PA', 'downingtown-pa/middletown-pa');
    // Two Franklins, but an existing redirect already points at one of them — kept, not rewritten.
    locationPage($site, 'Franklin, NJ', 'bedminster-nj/franklin-nj');
    locationPage($site, 'Franklin, PA', 'downingtown-pa/franklin-pa');
    Redirect::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'from_url' => '/franklin', 'to_url' => '/bedminster-nj/franklin-nj', 'code' => 301, 'status' => 'active', 'source' => 'migration']);
    // An existing /bedminster redirect that drifted to the PA town is corrected back to the office.
    Redirect::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'from_url' => '/bedminster', 'to_url' => '/doylestown-pa/bedminster-pa', 'code' => 301, 'status' => 'active', 'source' => 'migration']);

    $r = app(LegacyRedirectAuditor::class)->audit($site, apply: true);

    expect($r['fix'])->toContain(['from' => '/bedminster', 'to' => '/bedminster-nj', 'was' => '/doylestown-pa/bedminster-pa'])
        ->and($r['ok'])->toContain(['from' => '/franklin', 'to' => '/bedminster-nj/franklin-nj'])
        ->and($r['ambiguous'])->toBe([['from' => '/middletown', 'candidates' => ['/new-brunswick-nj/middletown-nj', '/downingtown-pa/middletown-pa'], 'current' => null]])
        ->and(array_column($r['create'], 'from'))->not->toContain('/middletown');

    expect(Redirect::withoutGlobalScope(SiteScope::class)->where('from_url', '/bedminster')->value('to_url'))->toBe('/bedminster-nj')
        ->and(Redirect::withoutGlobalScope(SiteScope::class)->where('from_url', '/franklin')->value('to_url'))->toBe('/bedminster-nj/franklin-nj')
        ->and(Redirect::withoutGlobalScope(SiteScope::class)->where('from_url', '/middletown')->exists())->toBeFalse();
});
