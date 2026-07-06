<?php

use App\Enums\VoiceStatus;
use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Site;
use App\Models\VoiceProfile;
use App\Styling\StyleActivator;
use App\Styling\StyleRecommender;
use App\Styling\StyleVariation;

function activator(?WordpressClientFactory $factory = null): StyleActivator
{
    return new StyleActivator($factory ?? Mockery::mock(WordpressClientFactory::class), new StyleRecommender);
}

it('resolves the operator override first', function () {
    $site = Site::factory()->create(['style_variation' => StyleVariation::Warm->value]);

    expect(activator()->resolve($site->fresh()))->toBe(StyleVariation::Warm);
});

it('resolves the voice recommendation when there is no override', function () {
    $site = Site::factory()->create(['style_variation' => null]);
    VoiceProfile::factory()->create([
        'site_id' => $site->id, 'status' => VoiceStatus::Active,
        'tone_axes' => ['formality' => 0.6, 'warmth' => 0.5], // direct_expert → Bold
        'audience' => ['primary' => 'homeowners'],
    ]);

    expect(activator()->resolve($site->fresh()))->toBe(StyleVariation::Bold);
});

it('falls back to Clean with no override and no voice', function () {
    $site = Site::factory()->create(['style_variation' => null]);

    expect(activator()->resolve($site->fresh()))->toBe(StyleVariation::Clean);
});

it('activates the resolved variation on the site WordPress', function () {
    $site = Site::factory()->create(['style_variation' => StyleVariation::Bold->value]);

    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('activateStyle')->once()->with('bold')->andReturn(['updated' => true, 'variation' => 'bold']);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->once()->andReturn($client);

    $result = activator($factory)->activate($site->fresh());

    expect($result['updated'])->toBeTrue()
        ->and($result['variation'])->toBe('bold');
});
