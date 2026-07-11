<?php

use App\ContentEngine\BlogQueue\BlogTargetQueue;
use App\ContentEngine\BlogQueue\DirectedIntake;
use App\ContentEngine\BlogQueue\PublishingMix;
use App\Enums\BlogTargetStatus;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IntakeType;
use App\Enums\KeywordIntent;
use App\Enums\KeywordSource;
use App\Enums\SpokeGranularity;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Interview\Expansion\CandidateSpoke;
use App\Interview\Prune\PruneEngine;
use App\Models\BlogTarget;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

function btSite(): Site
{
    return Site::factory()->create(['brand_name' => 'Sewer Gurus']);
}

/** A supporting (adjacent) candidate spoke on a fresh blueprint. */
function btSpoke(Site $site, string $name, string $keyword, ?KeywordIntent $intent, array $overrides = []): Spoke
{
    $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)->firstOrCreate(['site_id' => $site->id]);

    return Spoke::factory()->create(array_merge([
        'site_id' => $site->id,
        'silo_blueprint_id' => $blueprint->id,
        'silo' => 'Sump Pump Services',
        'name' => $name,
        'tag' => SpokeTag::Adjacent,
        'primary_keyword' => $keyword,
        'intent' => $intent,
        'status' => SpokeStatus::Candidate,
    ], $overrides));
}

function btQueued(Site $site, Silo $silo, string $query, int $volume = 100, ?string $intent = 'informational'): BlogTarget
{
    $keyword = Keyword::create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'query' => $query,
        'volume' => $volume, 'intent' => $intent, 'source' => KeywordSource::Seed, 'status' => 'candidate',
    ]);

    return BlogTarget::factory()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'keyword_id' => $keyword->id,
    ]);
}

it('parses the classifier intent field on a candidate spoke', function () {
    $spoke = CandidateSpoke::fromArray([
        'name' => 'Why is my basement wet in spring',
        'page_type' => 'content',
        'tag' => 'adjacent',
        'head_keyword' => 'why is my basement wet in spring',
        'intent' => 'Informational',
    ]);

    expect($spoke->intent)->toBe(KeywordIntent::Informational)
        ->and($spoke->toArray()['intent'])->toBe('informational');
});

it('routes supporting keywords by intent: informational → blog queue, commercial keeps the fold', function () {
    $site = btSite();
    // The silo needs a pillar so the plan groups it.
    btSpoke($site, 'Sump Pump Services', 'sump pump services', null, ['is_pillar' => true, 'tag' => SpokeTag::Core]);
    $informational = btSpoke($site, 'How long do sump pumps last', 'how long do sump pumps last', KeywordIntent::Informational);
    $commercial = btSpoke($site, 'Best battery backup sump pump', 'best battery backup sump pump', KeywordIntent::Commercial);

    $defaults = app(PruneEngine::class)->plan($site)->defaults();

    expect($defaults[$informational->id]['disposition'])->toBe('blog_target')
        ->and($defaults[$commercial->id]['disposition'])->toBe('fold');
});

it('finalize applies the blog_target disposition — offered, no fold target, and never a build page', function () {
    $site = btSite();
    btSpoke($site, 'Sump Pump Services', 'sump pump services', null, ['is_pillar' => true, 'tag' => SpokeTag::Core]);
    $spoke = btSpoke($site, 'How long do sump pumps last', 'how long do sump pumps last', KeywordIntent::Informational);

    app(PruneEngine::class)->finalize($site, []);

    $fresh = $spoke->fresh();
    expect($fresh->status)->toBe(SpokeStatus::Offered)
        ->and($fresh->granularity)->toBe(SpokeGranularity::BlogTarget)
        ->and($fresh->fold_into_id)->toBeNull();
});

it('sync enqueues offered blog-target keywords once, honors the exclusive home, and removes flipped rows', function () {
    $site = btSite();
    Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Services']);
    $spoke = btSpoke($site, 'How long do sump pumps last', 'how long do sump pumps last', KeywordIntent::Informational, [
        'status' => SpokeStatus::Offered, 'granularity' => SpokeGranularity::BlogTarget,
    ]);

    $queue = app(BlogTargetQueue::class);

    // First sync enqueues; a second sync is idempotent (unique keyword_id — never a duplicate).
    expect($queue->sync($site)['enqueued'])->toBe(1);
    expect($queue->sync($site)['enqueued'])->toBe(0);
    $target = BlogTarget::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->sole();
    expect($target->status)->toBe(BlogTargetStatus::Queued)
        ->and($target->keyword->query)->toBe('how long do sump pumps last');

    // EXCLUSIVE HOME: once the keyword targets a page it can never queue again.
    $page = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page]);
    $target->keyword->forceFill(['target_content_id' => $page->id])->save();
    $target->delete();
    expect($queue->sync($site)['enqueued'])->toBe(0);
    $target->keyword->forceFill(['target_content_id' => null])->save();
    $queue->sync($site);

    // Flip the spoke back to a fold → the QUEUED row leaves (moved, never duplicated).
    $spoke->forceFill(['granularity' => SpokeGranularity::Folded])->save();
    $result = $queue->sync($site);
    expect($result['removed'])->toBe(1)
        ->and(BlogTarget::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});

it('orders the queue by volume within the coverage seam and consumes exclusively', function () {
    $site = btSite();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    btQueued($site, $silo, 'low volume question', 50);
    $big = btQueued($site, $silo, 'why is my basement wet in spring', 900);

    $queue = app(BlogTargetQueue::class);
    expect($queue->top($site)->id)->toBe($big->id);

    // Consumption moves it out of the lane; the next top is the low-volume one.
    $article = Content::factory()->post()->create(['site_id' => $site->id, 'silo_id' => $silo->id]);
    $queue->markDrafted($big, $article);
    expect($big->fresh()->status)->toBe(BlogTargetStatus::Drafted)
        ->and($big->fresh()->article_ref)->toBe($article->id)
        ->and($queue->top($site)->keyword->query)->toBe('low volume question');

    // Publish flips drafted → published via the article ref.
    $queue->markPublishedByArticle($article);
    expect($big->fresh()->status)->toBe(BlogTargetStatus::Published);
});

it('a reactive article covering a queued term consumes it — same silo only, never double-assigned', function () {
    $site = btSite();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $other = Silo::factory()->create(['site_id' => $site->id]);
    $covered = btQueued($site, $silo, 'sump pump lifespan', 300);
    $elsewhere = btQueued($site, $other, 'french drain cost', 300);

    $article = Content::factory()->post()->create([
        'site_id' => $site->id,
        'silo_id' => $silo->id,
        'matched_silo_id' => $silo->id,
        'title' => 'What a sump pump lifespan really looks like',
        'body' => '<p>Most homeowners ask about sump pump lifespan after the first storm — and about french drain cost too.</p>',
    ]);

    $consumed = app(BlogTargetQueue::class)->consumeIfCovered($article);

    expect($consumed)->toBe(1)
        ->and($covered->fresh()->status)->toBe(BlogTargetStatus::Drafted)
        ->and($covered->fresh()->article_ref)->toBe($article->id)
        // The other silo's queue is untouched — the queue is silo metadata.
        ->and($elsewhere->fresh()->status)->toBe(BlogTargetStatus::Queued);

    // Already consumed → a second article never double-assigns it.
    $again = Content::factory()->post()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'matched_silo_id' => $silo->id,
        'title' => 'Sump pump lifespan, revisited', 'body' => '<p>sump pump lifespan</p>',
    ]);
    expect(app(BlogTargetQueue::class)->consumeIfCovered($again))->toBe(0)
        ->and($covered->fresh()->article_ref)->toBe($article->id);
});

it('directed intake pulls the top target as a directed candidate, idempotently', function () {
    $site = btSite();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Services']);
    $target = btQueued($site, $silo, 'why is my basement wet in spring', 900);

    $pulled = app(DirectedIntake::class)->pull($site);

    expect($pulled)->not->toBeNull()
        ->and($pulled['target']->id)->toBe($target->id);
    $candidate = $pulled['candidate'];
    expect($candidate->kind)->toBe(ContentKind::Post)
        ->and($candidate->intake_type)->toBe(IntakeType::Directed)
        ->and($candidate->silo_id)->toBe($silo->id)
        ->and($candidate->target_keyword_id)->toBe($target->keyword_id)
        ->and($candidate->status)->toBe(ContentStatus::Candidate);

    // Pulling again reuses the same candidate — never a duplicate article per keyword.
    $again = app(DirectedIntake::class)->pull($site);
    expect($again['candidate']->id)->toBe($candidate->id);
});

it('the publishing mix recommends directed until the window holds the ratio, per-tenant overridable', function () {
    $site = btSite();
    $mix = app(PublishingMix::class);

    expect($mix->ratio($site))->toBe(['directed' => 1, 'reactive' => 2])
        ->and($mix->nextLane($site))->toBe('directed'); // empty window → the directed slot is open

    // A directed post published inside the window satisfies the 1-in-3 ratio → reactive next.
    Content::factory()->post()->create([
        'site_id' => $site->id, 'intake_type' => IntakeType::Directed,
        'status' => ContentStatus::Published, 'published_at' => now(),
    ]);
    expect($mix->nextLane($site))->toBe('reactive');

    $site->forceFill(['directed_mix' => '3:1'])->save();
    expect($mix->ratio($site->fresh()))->toBe(['directed' => 3, 'reactive' => 1])
        ->and($mix->nextLane($site->fresh()))->toBe('directed');
});
