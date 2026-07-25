<?php

use App\ContentEngine\Reconcile\PostSiloReconciler;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

function rcSilo(Site $site, string $name, array $include): Silo
{
    return Silo::factory()->create([
        'site_id' => $site->id, 'name' => $name,
        'rule_set' => ['include_patterns' => $include, 'seed_terms' => $include, 'exclude_patterns' => []],
    ]);
}

function rcPost(Site $site, string $title, array $extra = []): Content
{
    return Content::factory()->post()->create(array_merge([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published, 'title' => $title,
    ], $extra));
}

it('re-routes a post orphaned by a silo rebuild (silo_id nulled) to the current silo by rule_set', function () {
    $site = Site::factory()->create();
    $sewer = rcSilo($site, 'Sewer', ['sewer']);
    // A rebuild deleted the post's old silo; the FK cascaded silo_id to null.
    $post = rcPost($site, 'Bridgewater Sewer Station Needs $3.9M', ['silo_id' => null, 'matched_silo_id' => null]);

    $result = app(PostSiloReconciler::class)->reconcile($site);

    expect($post->fresh()->silo_id)->toBe($sewer->id)
        ->and($post->fresh()->matched_silo_id)->toBe($sewer->id)
        ->and($result['rerouted'])->toBe([$post->id]);
});

it('adopts a still-valid matched_silo_id without re-matching', function () {
    $site = Site::factory()->create();
    $sewer = rcSilo($site, 'Sewer', ['sewer']);
    rcSilo($site, 'Drains', ['drain']);
    // silo_id null but matched_silo_id points at a live silo → adopt it (even if the title matches another).
    $post = rcPost($site, 'A drain story', ['silo_id' => null, 'matched_silo_id' => $sewer->id]);

    app(PostSiloReconciler::class)->reconcile($site);

    expect($post->fresh()->silo_id)->toBe($sewer->id);
});

it('leaves a post already on a current silo untouched', function () {
    $site = Site::factory()->create();
    $sewer = rcSilo($site, 'Sewer', ['sewer']);
    $post = rcPost($site, 'Anything', ['silo_id' => $sewer->id]);

    $result = app(PostSiloReconciler::class)->reconcile($site);

    expect($result['unchanged'])->toBe(1)->and($result['rerouted'])->toBe([])
        ->and($post->fresh()->silo_id)->toBe($sewer->id);
});

it('leaves a post unrouted (Uncategorized) when nothing in the current tree matches', function () {
    $site = Site::factory()->create();
    rcSilo($site, 'Sewer', ['sewer']);
    $post = rcPost($site, 'A post about kittens', ['silo_id' => null, 'matched_silo_id' => null]);

    $result = app(PostSiloReconciler::class)->reconcile($site);

    expect($post->fresh()->silo_id)->toBeNull()
        ->and($result['orphaned'])->toBe(1)
        ->and($result['rerouted'])->toBe([]);
});

it('the command re-routes and (with --repush) re-pushes the reconnected live posts', function () {
    Queue::fake();
    $site = Site::factory()->create(['brand_name' => 'SewerCo']);
    $sewer = rcSilo($site, 'Sewer', ['sewer']);
    rcPost($site, 'Cranford Sewer Bills Rising', ['silo_id' => null, 'matched_silo_id' => null]);

    Artisan::call('launchpad:reconcile-post-silos', ['site' => $site->id, '--repush' => true]);

    expect(Artisan::output())->toContain('1 post(s) re-routed');
    Queue::assertPushed(PublishContent::class);
    expect(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('kind', ContentKind::Post->value)->first()->silo_id)->toBe($sewer->id);
});
