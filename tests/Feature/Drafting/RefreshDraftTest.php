<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IntakeType;
use App\Models\Content;
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
        'version' => 1,
    ]);

    $claude = new FakeClaudeClient(Draft::post($claim->id, [
        'body' => '<p>Refreshed body with the latest rebate figures.</p>',
    ]));

    $request = DraftingHarness::postRequest($site)->refreshing($existing);

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
        ->and($refreshed->refresh_of_content_id)->toBe($existing->id)
        // the page keeps its URL across a refresh
        ->and($refreshed->slug)->toBe('original-tankless-explainer');
});
