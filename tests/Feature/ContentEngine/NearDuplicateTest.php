<?php

use App\ContentEngine\NearDuplicateDetector;
use App\Enums\NearDupTier;
use App\Integrations\Embedding\MockEmbeddingProvider;
use App\Models\Content;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

function existingPosts(Site $site, array $titles): Collection
{
    return collect($titles)->map(fn (string $t, int $i) => Content::factory()->post()->create([
        'site_id' => $site->id,
        'title' => $t,
        'slug' => Str::slug($t).'-'.$i,
        'body' => null,
    ]))->values();
}

test('a very-high overlap with a live page routes to refresh', function () {
    $site = Site::factory()->create();
    $existing = existingPosts($site, ['Tankless water heater rebate explained']);

    $result = (new NearDuplicateDetector(new MockEmbeddingProvider))
        ->detect('Tankless water heater rebate explained', $existing);

    expect($result->tier)->toBe(NearDupTier::Refresh)
        ->and($result->similarToContentId)->toBe($existing->first()->id)
        ->and($result->signal())->toBeGreaterThanOrEqual(0.9);
});

test('a moderate overlap flags the operator', function () {
    $site = Site::factory()->create();
    $existing = existingPosts($site, ['water heater rebate guide homeowners']);

    // Subset of the existing tokens — strong but not identical.
    $result = (new NearDuplicateDetector(new MockEmbeddingProvider))
        ->detect('water heater rebate guide', $existing);

    expect($result->tier)->toBe(NearDupTier::OperatorFlag);
});

test('a dissimilar candidate proceeds', function () {
    $site = Site::factory()->create();
    $existing = existingPosts($site, ['Tankless water heater rebate explained']);

    $result = (new NearDuplicateDetector(new MockEmbeddingProvider))
        ->detect('Choosing the right gutter guards for autumn leaves', $existing);

    expect($result->tier)->toBe(NearDupTier::Proceed);
});
