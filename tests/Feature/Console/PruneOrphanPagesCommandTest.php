<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\KeywordSource;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;

function cmdPrunePage(Site $site, array $o): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Service,
    ], $o));
}

function cmdSeedOrphans(Site $site): Content
{
    $kw = Keyword::create(['site_id' => $site->id, 'query' => 'sump pump replacement', 'source' => KeywordSource::Seed, 'status' => 'candidate']);
    cmdPrunePage($site, ['slug' => 'sump-pump-maintenance/sump-pump-replacement', 'status' => ContentStatus::Published, 'wp_post_id' => 565, 'target_keyword_id' => $kw->id]);
    $ghost = cmdPrunePage($site, ['slug' => 'sump-pump-maintenance-2/sump-pump-replacement', 'status' => ContentStatus::Candidate, 'target_keyword_id' => $kw->id]);

    return $ghost;
}

it('dry-runs by default — reports the orphan but deletes nothing', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $ghost = cmdSeedOrphans($site);

    $code = Artisan::call('launchpad:prune-orphan-pages', ['--site' => 'SPG']);
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and($out)->toContain('DRY RUN')
        ->and($out)->toContain('/sump-pump-maintenance-2/sump-pump-replacement');
    expect($ghost->fresh()->trashed())->toBeFalse();
});

it('--apply soft-deletes the orphan', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $ghost = cmdSeedOrphans($site);

    Artisan::call('launchpad:prune-orphan-pages', ['--site' => 'SPG', '--apply' => true]);

    expect($ghost->fresh()->trashed())->toBeTrue();
});

it('reports a clean bill when there is nothing to prune', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    cmdPrunePage($site, ['slug' => 'basement-waterproofing', 'status' => ContentStatus::Published, 'wp_post_id' => 1]);

    expect(Artisan::call('launchpad:prune-orphan-pages', ['--site' => 'SPG']))->toBe(0);
    expect(Artisan::output())->toContain('No never-published duplicate');
});

it('errors for an unknown site', function () {
    expect(Artisan::call('launchpad:prune-orphan-pages', ['--site' => 'Nope Inc']))->toBe(1);
});
