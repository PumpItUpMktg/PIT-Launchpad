<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Resources\KeywordResource\Pages\ListKeywords;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Operator\Coverage\GridKeywordSelector;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** A top-level service/hub page targeting $keyword (parent_content_id null). */
function topLevelPage(Site $site, ?Keyword $keyword, string $type = 'service'): Content
{
    return Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page->value, 'page_type' => $type,
        'parent_content_id' => null, 'target_keyword_id' => $keyword?->id,
    ]);
}

it('flags the target keyword of each top-level service/hub page, skipping pages without one', function () {
    $site = Site::factory()->create();
    $kwInstall = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump installation', 'is_grid_keyword' => false]);
    $kwWaterproof = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'basement waterproofing', 'is_grid_keyword' => false]);
    $kwNested = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair', 'is_grid_keyword' => false]);

    topLevelPage($site, $kwInstall);                 // top-level → flagged
    topLevelPage($site, $kwWaterproof, 'hub');       // top-level hub → flagged
    topLevelPage($site, null);                        // top-level, no target keyword → skipped

    // A NESTED service page (has a hub parent) — its target keyword must NOT be flagged.
    $hub = topLevelPage($site, null, 'hub');
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page->value, 'page_type' => PageType::Service->value,
        'parent_content_id' => $hub->id, 'target_keyword_id' => $kwNested->id,
    ]);

    $result = app(GridKeywordSelector::class)->addTopLevelServices($site);

    expect($result['flagged'])->toBe(2)
        ->and($result['skipped'])->toBe(2)           // the two top-level pages with no target keyword
        ->and($kwInstall->refresh()->is_grid_keyword)->toBeTrue()
        ->and($kwWaterproof->refresh()->is_grid_keyword)->toBeTrue()
        ->and($kwNested->refresh()->is_grid_keyword)->toBeFalse();   // nested → untouched
});

it('is idempotent — a second run flags nothing new', function () {
    $site = Site::factory()->create();
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => false]);
    topLevelPage($site, $kw);

    expect(app(GridKeywordSelector::class)->addTopLevelServices($site)['flagged'])->toBe(1)
        ->and(app(GridKeywordSelector::class)->addTopLevelServices($site)['flagged'])->toBe(0)
        ->and($kw->refresh()->is_grid_keyword)->toBeTrue();
});

it('adds top-level services to the grid from the Targets & gaps header action, scoped to the working tenant', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $mine = Site::factory()->create();
    $other = Site::factory()->create();
    $mineKw = Keyword::factory()->create(['site_id' => $mine->id, 'query' => 'a', 'is_grid_keyword' => false]);
    $otherKw = Keyword::factory()->create(['site_id' => $other->id, 'query' => 'b', 'is_grid_keyword' => false]);
    topLevelPage($mine, $mineKw);
    topLevelPage($other, $otherKw);
    app(ActiveTenant::class)->set($mine->id); // the action scopes to the lock, not a Tenant filter

    Livewire::test(ListKeywords::class)
        ->callAction('addTopLevelToGrid');

    expect($mineKw->refresh()->is_grid_keyword)->toBeTrue()
        ->and($otherKw->refresh()->is_grid_keyword)->toBeFalse();   // other tenant untouched
});
