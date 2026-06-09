<?php

use App\Client\ClientContext;
use App\Enums\FeedOrigin;
use App\Filament\Client\Resources\NewsSourceResource;
use App\Models\Site;
use App\Models\Source;

it('shows only the current site\'s client feeds — never generated or another tenant\'s', function () {
    $site = Site::factory()->create();
    $other = Site::factory()->create();

    $mine = Source::factory()->create(['site_id' => $site->id, 'origin' => FeedOrigin::Client->value]);
    Source::factory()->create(['site_id' => $site->id, 'origin' => FeedOrigin::Generated->value]); // operator-managed — hidden
    Source::factory()->create(['site_id' => $other->id, 'origin' => FeedOrigin::Client->value]);    // another tenant — hidden

    $context = Mockery::mock(ClientContext::class);
    $context->shouldReceive('site')->andReturn($site);
    app()->instance(ClientContext::class, $context);

    $ids = NewsSourceResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toBe([$mine->id]);
});
