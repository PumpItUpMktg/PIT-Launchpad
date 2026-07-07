<?php

use App\Enums\BuildSource;
use App\Enums\BuildStatus;
use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

it('backfills target_keyword_id on a pre-fix page from its spoke, and skips one with no spoke', function () {
    $site = Site::factory()->create();
    $blueprint = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    $spoke = Spoke::factory()->create([
        'site_id' => $site->id, 'silo_blueprint_id' => $blueprint->id, 'is_pillar' => false,
        'name' => 'Drain Cleaning', 'primary_keyword' => 'drain cleaning newark', 'volume' => 300,
    ]);

    // A page built before the fix: null target keyword, linked to its spoke via a BuildPage.
    $page = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'slug' => 'drain-cleaning', 'title' => 'Drain Cleaning', 'target_keyword_id' => null,
    ]);
    BuildPage::factory()->create([
        'site_id' => $site->id, 'source' => BuildSource::Service, 'page_key' => $spoke->id,
        'title' => 'Drain Cleaning', 'recipe' => 'x', 'status' => BuildStatus::Queued,
        'spoke_id' => $spoke->id, 'content_id' => $page->id,
    ]);

    // A page with no spoke (e.g. a town page) — stays null.
    $noSpoke = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'slug' => 'newark', 'title' => 'Newark', 'target_keyword_id' => null,
    ]);

    $this->artisan('launchpad:backfill-page-keywords', ['site' => $site->id])->assertSuccessful();

    $fresh = Content::withoutGlobalScope(SiteScope::class)->find($page->id);
    expect($fresh->target_keyword_id)->not->toBeNull()
        ->and($fresh->targetKeyword()->withoutGlobalScope(SiteScope::class)->first()->query)->toBe('drain cleaning newark');

    expect(Content::withoutGlobalScope(SiteScope::class)->find($noSpoke->id)->target_keyword_id)->toBeNull();
});
