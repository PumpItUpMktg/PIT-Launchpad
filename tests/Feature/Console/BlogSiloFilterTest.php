<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Filament\Console\Pages\BlogCandidates;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use App\Models\User;
use App\OpsConsole\PublishedContentBoard;

it('filters candidates by the selected silo', function () {
    $this->actingAs(User::factory()->create());
    $site = Site::factory()->create();
    $siloA = Silo::factory()->create(['site_id' => $site->id]);
    $siloB = Silo::factory()->create(['site_id' => $site->id]);

    $inA = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Candidate, 'matched_silo_id' => $siloA->id, 'title' => 'A story']);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Candidate, 'matched_silo_id' => $siloB->id, 'title' => 'B story']);

    $page = new BlogCandidates;
    $page->siteId = $site->id;

    // No silo filter → both.
    expect(collect($page->getCandidatesProperty())->pluck('id'))->toHaveCount(2);

    // Filter to silo A → only A.
    $page->siloId = $siloA->id;
    expect(collect($page->getCandidatesProperty())->pluck('id')->all())->toBe([$inA->id]);

    // The dropdown offers this site's silos.
    expect(array_keys($page->getSiloFilterOptionsProperty()))->toContain($siloA->id, $siloB->id);
});

it('filters published posts and pages by silo', function () {
    $this->actingAs(User::factory()->create());
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $siloA = Silo::factory()->create(['site_id' => $site->id]);
    $siloB = Silo::factory()->create(['site_id' => $site->id]);

    $postA = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'matched_silo_id' => $siloA->id, 'wp_post_id' => 1, 'title' => 'Post A']);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'matched_silo_id' => $siloB->id, 'wp_post_id' => 2, 'title' => 'Post B']);
    $pageA = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'status' => ContentStatus::Published, 'silo_id' => $siloA->id, 'wp_post_id' => 3, 'title' => 'Page A', 'slug' => 'page-a']);

    // The read model applies the silo filter to both posts and pages (section null → all buckets built).
    $board = app(PublishedContentBoard::class)->forSite($site->id, $siloA->id);

    expect(collect($board['blog'])->pluck('id')->all())->toBe([$postA->id])
        ->and(collect($board['service'])->pluck('id')->all())->toBe([$pageA->id]);
});

it('clears the silo filter when the site changes', function () {
    $this->actingAs(User::factory()->admin()->create());
    $a = Site::factory()->create();
    $b = Site::factory()->create();

    $page = new BlogCandidates;
    $page->siteId = $a->id;
    $page->siloId = Silo::factory()->create(['site_id' => $a->id])->id;

    $page->siteId = $b->id;
    $page->updatedSiteId();

    expect($page->siloId)->toBeNull();
});
