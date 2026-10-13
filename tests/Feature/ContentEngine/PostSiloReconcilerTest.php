<?php

use App\ContentEngine\Reconcile\AiSiloRouter;
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
use Tests\Support\FakeClaudeClient;

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

    expect(Artisan::output())->toContain('1 post(s) re-routed to a current silo (1 by rule_set, 0 by silo name, 0 by the model), 0 left unrouted (0 of them published');
    Queue::assertPushed(PublishContent::class);
    expect(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('kind', ContentKind::Post->value)->first()->silo_id)->toBe($sewer->id);
});

it('routes an informational title by the silo NAME when no rule_set phrase matches — only when exactly one silo wins', function () {
    $site = Site::factory()->create();
    // Rule_sets carry commercial phrases only; the names carry the topics.
    $backup = rcSilo($site, 'Backup Sump Pump Solutions', ['battery backup sump pump installation']);
    $waterproofing = rcSilo($site, 'Basement Waterproofing', ['basement waterproofing service']);
    $radon = rcSilo($site, 'Radon Mitigation', ['radon mitigation system']);
    rcSilo($site, 'Sump Pump Installation', ['sump pump installation']);
    rcSilo($site, 'Sump Pump Maintenance', ['sump pump maintenance']);

    $flood = rcPost($site, 'Protect Your Basement Before Flash Floods Strike Bucks', ['silo_id' => null, 'matched_silo_id' => null]);
    $radonPost = rcPost($site, 'Does Weather Affect Radon Levels in Your Home', ['silo_id' => null, 'matched_silo_id' => null]);
    $battery = rcPost($site, 'What To Do When the Power Goes Out', ['silo_id' => null, 'matched_silo_id' => null, 'meta' => ['seo' => ['meta_description' => 'A battery backup keeps the sump pump running through an outage.']]]);
    // "sump" and "pump" are shared by 3 of 5 silos → not distinguishing; nothing else in the title → unrouted, not guessed.
    $tie = rcPost($site, 'How Often Should a Sump Pump Run', ['silo_id' => null, 'matched_silo_id' => null]);
    $kittens = rcPost($site, 'A post about kittens', ['silo_id' => null, 'matched_silo_id' => null]);

    $result = app(PostSiloReconciler::class)->reconcile($site);

    expect($flood->fresh()->silo_id)->toBe($waterproofing->id)         // "basement"
        ->and($radonPost->fresh()->silo_id)->toBe($radon->id)         // "radon"
        ->and($battery->fresh()->silo_id)->toBe($backup->id)          // "backup", from the meta description
        ->and($tie->fresh()->silo_id)->toBeNull()
        ->and($kittens->fresh()->silo_id)->toBeNull()
        ->and($result['by_rule'])->toBe(0)
        ->and($result['by_name'])->toBe(3)
        ->and($result['orphaned'])->toBe(2)
        ->and($result['orphaned_published'])->toBe(2);
});

it('--ai files the published leftovers through the scoring model — exact names only, never drafts, never a guess', function () {
    $site = Site::factory()->create(['brand_name' => 'AiCo']);
    rcSilo($site, 'Sump Pump Installation', ['sump pump installation']);
    $maintenance = rcSilo($site, 'Sump Pump Maintenance', ['sump pump maintenance']);
    rcSilo($site, 'Radon Mitigation', ['radon mitigation system']);
    // Shared-word titles the rules cannot place.
    $published = rcPost($site, 'How Often Should a Sump Pump Run', ['silo_id' => null, 'matched_silo_id' => null]);
    $draft = rcPost($site, 'How Long Do Sump Pumps Last', ['silo_id' => null, 'matched_silo_id' => null, 'status' => ContentStatus::NeedsReview]);

    // The model answers "Sump Pump Maintenance" for everything it is asked.
    $fake = new FakeClaudeClient('{"topic":"Sump Pump Maintenance"}');
    app()->instance(AiSiloRouter::class, new AiSiloRouter($fake));

    // Without --ai nothing moves.
    $result = app(PostSiloReconciler::class)->reconcile($site);
    expect($result['by_ai'])->toBe(0)->and($published->fresh()->silo_id)->toBeNull()->and($fake->prompts)->toBe([]);

    $result = app(PostSiloReconciler::class)->reconcile($site, aiPublished: true);
    expect($published->fresh()->silo_id)->toBe($maintenance->id)
        ->and($draft->fresh()->silo_id)->toBeNull()                 // never a draft
        ->and($result['by_ai'])->toBe(1)
        ->and($result['orphaned'])->toBe(1)
        ->and(count($fake->prompts))->toBe(1)
        ->and($fake->prompts[0])->toContain('How Often Should a Sump Pump Run')->toContain('- Sump Pump Maintenance');

    // An answer naming no listed topic (or null) routes nothing.
    $unknown = rcPost($site, 'What a Sump Pump Pit Should Look Like', ['silo_id' => null, 'matched_silo_id' => null]);
    app()->instance(AiSiloRouter::class, new AiSiloRouter(new FakeClaudeClient('{"topic":"Basement Waterproofing"}')));
    app(PostSiloReconciler::class)->reconcile($site, aiPublished: true);
    expect($unknown->fresh()->silo_id)->toBeNull();

    Artisan::call('launchpad:reconcile-post-silos', ['site' => $site->id, '--ai' => true]);
    expect(Artisan::output())->toContain('by the model');
});
