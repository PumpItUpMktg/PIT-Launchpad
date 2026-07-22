<?php

use App\Branding\BrandVariationBuilder;
use App\Enums\VoiceStatus;
use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\VoiceProfile;
use App\Publishing\Chrome\SiteProfileAssembler;
use App\Styling\StyleActivator;
use App\Styling\StyleRecommender;
use App\Styling\StyleVariation;
use App\Styling\VariationThemeJson;

function activator(?WordpressClientFactory $factory = null): StyleActivator
{
    return new StyleActivator(
        $factory ?? Mockery::mock(WordpressClientFactory::class),
        new StyleRecommender,
        app(SiteProfileAssembler::class),
        new BrandVariationBuilder,
        new VariationThemeJson,
    );
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

it('activates the resolved variation on the site WordPress, sending its palette inline', function () {
    $site = Site::factory()->create(['style_variation' => StyleVariation::Bold->value]);

    $client = Mockery::mock(WordpressClient::class);
    // The curated push carries the full theme.json inline (palette + typography), so it paints the
    // chosen colors even when the deployed theme's styles/{slug}.json is stale — Bold's primary #111827.
    $client->shouldReceive('activateStyleVariation')->once()
        ->with('bold', Mockery::on(function (array $json): bool {
            $primary = collect($json['settings']['color']['palette'])->firstWhere('slug', 'primary');

            return $json['title'] === 'Bold & Direct' && $primary['color'] === '#111827';
        }))
        ->andReturn(['updated' => true, 'variation' => 'bold']);
    // The brand push also populates the header/footer chrome in the same step.
    $client->shouldReceive('pushSiteProfile')->once()->andReturn(['updated' => true]);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->once()->andReturn($client);

    $result = activator($factory)->activate($site->fresh());

    expect($result['updated'])->toBeTrue()
        ->and($result['variation'])->toBe('bold');
});

it('activates the logo-derived variation inline when use_logo_colors is set', function () {
    $site = Site::factory()->create(['use_logo_colors' => true]);
    SiteBranding::factory()->create(['site_id' => $site->id, 'logo_set' => ['primary' => '#EA580C', 'accent' => '#0B1F33']]);

    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('activateStyleVariation')->once()
        ->with('brand', Mockery::on(function (array $json): bool {
            $primary = collect($json['settings']['color']['palette'])->firstWhere('slug', 'primary');

            return $json['title'] === 'Your brand colors' && $primary['color'] === '#ea580c';
        }))
        ->andReturn(['updated' => true]);
    $client->shouldReceive('pushSiteProfile')->once()->andReturn(['updated' => true]);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->once()->andReturn($client);

    $result = activator($factory)->activate($site->fresh());

    expect($result['updated'])->toBeTrue()
        ->and($result['variation'])->toBe('brand');
});

it('falls back to the curated variation when use_logo_colors is set but no logo palette exists', function () {
    $site = Site::factory()->create(['use_logo_colors' => true, 'style_variation' => StyleVariation::Bold->value]);
    // No SiteBranding / logo colors → curated path.

    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('activateStyleVariation')->once()->with('bold', Mockery::type('array'))->andReturn(['updated' => true]);
    $client->shouldReceive('pushSiteProfile')->once()->andReturn(['updated' => true]);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->once()->andReturn($client);

    expect(activator($factory)->activate($site->fresh())['variation'])->toBe('bold');
});

it('a chrome-push failure does not fail the style activation', function () {
    $site = Site::factory()->create(['style_variation' => StyleVariation::Bold->value]);

    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('activateStyleVariation')->once()->andReturn(['updated' => true, 'variation' => 'bold']);
    $client->shouldReceive('pushSiteProfile')->once()->andThrow(new RuntimeException('WP down'));
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->once()->andReturn($client);

    $result = activator($factory)->activate($site->fresh());

    expect($result['updated'])->toBeTrue(); // style still stands
});
