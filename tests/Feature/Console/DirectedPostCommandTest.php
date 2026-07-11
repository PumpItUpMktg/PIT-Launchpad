<?php

use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Drafting\Drafter;
use App\Enums\BlogTargetStatus;
use App\Enums\ContentStatus;
use App\Enums\IntakeType;
use App\Enums\KeywordIntent;
use App\Enums\KeywordSource;
use App\Integrations\Claude\ClaudeClient;
use App\Models\BlogTarget;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Publishing\RenderCoordinator;
use App\Publishing\RenderOutcome;
use Illuminate\Support\Collection;
use Tests\Support\Draft;
use Tests\Support\FakeClaudeClient;

function directedTarget(Site $site, Silo $silo, string $query, int $volume = 500): BlogTarget
{
    $keyword = Keyword::create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'query' => $query,
        'volume' => $volume, 'intent' => 'informational', 'source' => KeywordSource::Seed, 'status' => 'candidate',
    ]);

    return BlogTarget::factory()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'keyword_id' => $keyword->id,
    ]);
}

it('drafts the top queued blog target through generate-post --directed and consumes it', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Services']);
    $target = directedTarget($site, $silo, 'why is my basement wet in spring');

    app()->bind(Drafter::class, fn () => new Drafter(new DraftCall(new FakeClaudeClient(Draft::json([
        'body' => '<p>Spring water tables rise, and a basement without working drainage shows it first. Here is why — and what actually fixes it.</p>',
    ])))));
    $renders = Mockery::mock(RenderCoordinator::class);
    $renders->shouldReceive('render')->andReturn(new RenderOutcome(new Collection, true, []));
    app()->instance(RenderCoordinator::class, $renders);

    test()->artisan('launchpad:generate-post', ['--directed' => true, '--site' => $site->id])
        ->expectsOutputToContain('Directed target: "why is my basement wet in spring"')
        ->assertSuccessful();

    $fresh = $target->fresh();
    expect($fresh->status)->toBe(BlogTargetStatus::Drafted)
        ->and($fresh->article_ref)->not->toBeNull();

    $article = Content::withoutGlobalScope(SiteScope::class)->find($fresh->article_ref);
    expect($article->intake_type)->toBe(IntakeType::Directed)
        ->and($article->silo_id)->toBe($silo->id)
        ->and($article->target_keyword_id)->toBe($target->keyword_id)
        ->and($article->status)->toBe(ContentStatus::NeedsReview)
        ->and($article->draft_trigger?->value)->toBe('gap');
});

it('an empty queue is a clean no-op, and --directed without a site fails actionably', function () {
    $site = Site::factory()->create();

    test()->artisan('launchpad:generate-post', ['--directed' => true, '--site' => $site->id])
        ->expectsOutputToContain('queue is empty')
        ->assertSuccessful();

    test()->artisan('launchpad:generate-post', ['--directed' => true])
        ->expectsOutputToContain('--directed needs --site')
        ->assertFailed();
});

it('backfills intent on untagged spokes and keywords via classify-intent', function () {
    $site = Site::factory()->create();
    $blueprint = SiloBlueprint::create(['site_id' => $site->id]);
    $spoke = Spoke::factory()->create([
        'site_id' => $site->id, 'silo_blueprint_id' => $blueprint->id,
        'name' => 'Sump Pump Repair', 'primary_keyword' => 'sump pump repair', 'intent' => null,
    ]);
    $tagged = Spoke::factory()->create([
        'site_id' => $site->id, 'silo_blueprint_id' => $blueprint->id,
        'name' => 'Battery Backups', 'primary_keyword' => 'best battery backup sump pump',
        'intent' => KeywordIntent::Commercial,
    ]);
    $keyword = Keyword::create([
        'site_id' => $site->id, 'query' => 'why is my basement wet in spring',
        'source' => KeywordSource::Seed, 'status' => 'candidate',
    ]);

    app()->instance(ClaudeClient::class, new FakeClaudeClient(json_encode([
        'sump pump repair' => 'transactional',
        'why is my basement wet in spring' => 'informational',
    ])));

    test()->artisan('launchpad:classify-intent', ['--site' => $site->id])->assertSuccessful();

    expect($spoke->fresh()->intent)->toBe(KeywordIntent::Transactional)
        ->and($keyword->fresh()->intent)->toBe('informational')
        // Already-tagged records are untouched (idempotent backfill).
        ->and($tagged->fresh()->intent)->toBe(KeywordIntent::Commercial);
});
