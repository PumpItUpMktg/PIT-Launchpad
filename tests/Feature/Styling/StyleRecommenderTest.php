<?php

use App\Enums\VoiceStatus;
use App\Models\VoiceProfile;
use App\Styling\StyleRecommender;
use App\Styling\StyleSignals;
use App\Styling\StyleVariation;

function recommend(float $formality, float $warmth, string $audience = '', string $credibility = ''): StyleVariation
{
    return (new StyleRecommender)->recommend(new StyleSignals($formality, $warmth, $audience, $credibility));
}

it('maps a direct/expert voice to Bold', function () {
    // direct_expert tone (formality .6 / warmth .5)
    expect(recommend(0.6, 0.5, 'homeowners'))->toBe(StyleVariation::Bold);
});

it('maps a commercial audience to Bold regardless of a warmer tone', function () {
    expect(recommend(0.5, 0.7, 'property managers and commercial facilities'))->toBe(StyleVariation::Bold)
        ->and(recommend(0.4, 0.8, 'general contractors'))->toBe(StyleVariation::Bold);
});

it('maps a genuinely warm voice to Warm', function () {
    // friendly_warm tone (formality .3 / warmth .85)
    expect(recommend(0.3, 0.85, 'local homeowners'))->toBe(StyleVariation::Warm);
});

it('maps the professional-warm middle to Clean (the trustworthy default)', function () {
    // professional_warm tone (formality .55 / warmth .7)
    expect(recommend(0.55, 0.7, 'homeowners'))->toBe(StyleVariation::Clean)
        ->and(recommend(0.5, 0.6, ''))->toBe(StyleVariation::Clean);
});

it('derives signals from an active VoiceProfile', function () {
    $voice = VoiceProfile::factory()->create([
        'status' => VoiceStatus::Active,
        'tone_axes' => ['formality' => 0.6, 'warmth' => 0.5],
        'audience' => ['primary' => 'homeowners'],
        'persona' => ['credibility' => 'licensed, 20 years'],
    ]);

    $signals = StyleSignals::fromVoiceProfile($voice);

    expect($signals->formality)->toBe(0.6)
        ->and($signals->warmth)->toBe(0.5)
        ->and($signals->audience)->toBe('homeowners')
        ->and((new StyleRecommender)->recommend($signals))->toBe(StyleVariation::Bold);
});

it('exposes the six-role palette + typography tokens and the alternates per variation', function () {
    $bold = StyleVariation::Bold->tokens();
    expect($bold['primary'])->toBe('#111827')
        ->and($bold['accent'])->toBe('#E4572E')          // tokens.accent === palette.highlight
        ->and($bold['heading_font'])->toBe('Archivo')
        ->and($bold['heading_weight'])->toBe(800)
        ->and($bold['radius'])->toBe('3px');

    // The full six-role palette is the picker contract.
    $p = StyleVariation::Bold->palette();
    expect($p)->toHaveKeys(['base', 'surface', 'text', 'primary', 'highlight', 'button', 'muted', 'border', 'on_accent', 'on_button'])
        ->and($p['button'])->toBe('#E4572E');

    expect(StyleVariation::Clean->tokens()['primary'])->toBe('#123B6B')
        ->and(StyleVariation::Warm->tokens()['heading_font'])->toBe('Bricolage Grotesque')
        ->and(StyleVariation::Warm->tokens()['radius'])->toBe('18px');

    // Ten variations now; alternates are the other nine, stable order, never including self.
    expect(StyleVariation::cases())->toHaveCount(10)
        ->and(StyleVariation::Clean->alternates())->toHaveCount(9)
        ->and(StyleVariation::Clean->alternates())->not->toContain(StyleVariation::Clean)
        ->and(StyleVariation::Clean->alternates()[0])->toBe(StyleVariation::Bold); // declaration order, self removed
});
