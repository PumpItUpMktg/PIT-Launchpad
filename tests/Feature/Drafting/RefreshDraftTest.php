<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\DraftTrigger;
use App\Enums\IntakeType;
use App\Enums\RefreshTrigger;
use App\Models\Content;
use App\Models\RefreshEvent;
use App\Models\Scopes\SiteScope;
use Tests\Support\Draft;
use Tests\Support\DraftingHarness;
use Tests\Support\FakeClaudeClient;

test('the refresh path re-drafts an existing row in place instead of creating a new one', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();

    $existing = Content::create([
        'site_id' => $site->id,
        'kind' => ContentKind::Post,
        'intake_type' => IntakeType::Reactive,
        'status' => ContentStatus::Published,
        'title' => 'Original tankless explainer',
        'slug' => 'original-tankless-explainer',
        'body' => '<p>Old body.</p>',
        // The original directed lane — must survive the refresh untouched.
        'draft_trigger' => DraftTrigger::Gap,
        'version' => 1,
    ]);

    $claude = new FakeClaudeClient(Draft::post($claim->id, [
        'body' => '<p>Refreshed body with the latest rebate figures.</p>',
    ]));

    $request = DraftingHarness::postRequest($site)->refreshing($existing, RefreshTrigger::NearDuplicateMerge);

    expect($request->isRefresh())->toBeTrue();

    $result = DraftingHarness::engine($claude)->run($request);

    // No new row was inserted — the existing one was updated in place.
    expect(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1)
        ->and($result->wasRefresh)->toBeTrue();

    $refreshed = $existing->fresh();
    expect($refreshed->id)->toBe($existing->id)
        ->and($refreshed->status)->toBe(ContentStatus::NeedsReview)
        ->and($refreshed->version)->toBe(2)
        ->and($refreshed->body)->toContain('Refreshed body')
        // The FK stays null on an in-place refresh (reserved for a future
        // supersedes/version model).
        ->and($refreshed->refresh_of_content_id)->toBeNull()
        // The original lane is preserved — the refresh cause lives on the event.
        ->and($refreshed->draft_trigger)->toBe(DraftTrigger::Gap)
        // The page keeps its URL across a refresh.
        ->and($refreshed->slug)->toBe('original-tankless-explainer')
        // Denormalized cache for cheap list reads.
        ->and($refreshed->refresh_count)->toBe(1)
        ->and($refreshed->last_refreshed_at)->not->toBeNull();
});

test('an in-place refresh writes exactly one RefreshEvent carrying the refresh cause', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();

    $existing = Content::create([
        'site_id' => $site->id,
        'kind' => ContentKind::Post,
        'intake_type' => IntakeType::Reactive,
        'status' => ContentStatus::Published,
        'title' => 'News-driven explainer',
        'slug' => 'news-driven-explainer',
        'body' => '<p>Old.</p>',
        'version' => 1,
    ]);

    $claude = new FakeClaudeClient(Draft::post($claim->id));
    $request = DraftingHarness::postRequest($site)->refreshing($existing, RefreshTrigger::NewsDevelopment);

    DraftingHarness::engine($claude)->run($request);

    $events = RefreshEvent::withoutGlobalScope(SiteScope::class)
        ->where('content_id', $existing->id)
        ->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->trigger)->toBe(RefreshTrigger::NewsDevelopment)
        ->and($events->first()->site_id)->toBe($site->id);
});

test('a non-refresh draft writes no RefreshEvent and leaves the FK null', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();

    $content = DraftingHarness::engine(new FakeClaudeClient(Draft::post($claim->id)))
        ->run(DraftingHarness::postRequest($site))
        ->content
        ->fresh();

    expect($content->refresh_of_content_id)->toBeNull()
        ->and($content->refresh_count)->toBe(0)
        ->and($content->last_refreshed_at)->toBeNull()
        ->and(RefreshEvent::withoutGlobalScope(SiteScope::class)->count())->toBe(0);
});
