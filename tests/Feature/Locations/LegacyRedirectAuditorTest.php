<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Locations\LegacyRedirectAuditor;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

function locationPage(Site $site, string $title, string $slug): Content
{
    return Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'title' => $title, 'slug' => $slug,
    ]);
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
