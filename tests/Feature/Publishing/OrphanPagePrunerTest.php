<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\KeywordSource;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Site;
use App\Publishing\OrphanPagePruner;

function pagerow(Site $site, array $o): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Service,
    ], $o));
}

it('prunes never-published duplicates but keeps the live canonical', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $kw = Keyword::create(['site_id' => $site->id, 'query' => 'sump pump replacement', 'source' => KeywordSource::Seed, 'status' => 'candidate']);

    $live = pagerow($site, ['slug' => 'sump-pump-maintenance/sump-pump-replacement', 'status' => ContentStatus::Published, 'wp_post_id' => 565, 'target_keyword_id' => $kw->id]);
    $d1 = pagerow($site, ['slug' => 'sump-pump-maintenance-2/sump-pump-replacement', 'status' => ContentStatus::Candidate, 'target_keyword_id' => $kw->id]);
    $d2 = pagerow($site, ['slug' => 'sump-pump-maintenance/sump-pump-replacement-2', 'status' => ContentStatus::NeedsReview, 'target_keyword_id' => $kw->id]);
    $d3 = pagerow($site, ['slug' => 'sump-pumps/sump-pump-replacement', 'status' => ContentStatus::Approved, 'target_keyword_id' => $kw->id]);

    $pruner = new OrphanPagePruner;
    $plan = $pruner->plan($site);

    expect($plan['prune'])->toHaveCount(3)
        ->and(collect($plan['prune'])->pluck('row.id')->all())->toEqualCanonicalizing([$d1->id, $d2->id, $d3->id]);

    $pruner->apply($plan['prune']);

    expect($live->fresh()->trashed())->toBeFalse()      // canonical untouched
        ->and($d1->fresh()->trashed())->toBeTrue()       // ghosts soft-deleted
        ->and($d2->fresh()->trashed())->toBeTrue()
        ->and($d3->fresh()->trashed())->toBeTrue();
});

it('leaves every draft alone when there is no live canonical', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    pagerow($site, ['slug' => 'a/french-drain-installation', 'status' => ContentStatus::Candidate]);
    pagerow($site, ['slug' => 'b/french-drain-installation', 'status' => ContentStatus::NeedsReview]);

    expect((new OrphanPagePruner)->plan($site)['prune'])->toBe([]);
});

it('never prunes a live/pushed row, and flags a leaf with two live pages', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    pagerow($site, ['slug' => 'basement-waterproofing/french-drain-installation', 'status' => ContentStatus::Published, 'wp_post_id' => 1]);
    pagerow($site, ['slug' => 'yard-drainage/french-drain-installation', 'status' => ContentStatus::Published, 'wp_post_id' => 2]);

    $plan = (new OrphanPagePruner)->plan($site);

    expect($plan['prune'])->toBe([])
        ->and($plan['flagged'])->toHaveCount(1)
        ->and($plan['flagged'][0]['leaf'])->toBe('french-drain-installation');
});

it('does not merge two different targets that share a leaf', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $kwLive = Keyword::create(['site_id' => $site->id, 'query' => 'sump pump replacement', 'source' => KeywordSource::Seed, 'status' => 'candidate']);
    $kwOther = Keyword::create(['site_id' => $site->id, 'query' => 'commercial pump replacement', 'source' => KeywordSource::Seed, 'status' => 'candidate']);

    pagerow($site, ['slug' => 'maintenance/replacement', 'status' => ContentStatus::Published, 'wp_post_id' => 9, 'target_keyword_id' => $kwLive->id]);
    pagerow($site, ['slug' => 'commercial/replacement', 'status' => ContentStatus::Candidate, 'target_keyword_id' => $kwOther->id]);

    // Same leaf ("replacement") but different targets → not pruned.
    expect((new OrphanPagePruner)->plan($site)['prune'])->toBe([]);
});
