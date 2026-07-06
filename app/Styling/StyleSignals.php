<?php

namespace App\Styling;

use App\Models\VoiceProfile;

/**
 * The brand/voice signals the {@see StyleRecommender} reads — the tone/audience/credibility answers
 * captured in the Brand step's voice setup. A thin value object so the recommender is testable in
 * isolation and decoupled from the {@see VoiceProfile} storage shape.
 */
final class StyleSignals
{
    public function __construct(
        public readonly float $formality,
        public readonly float $warmth,
        public readonly string $audience = '',
        public readonly string $credibility = '',
    ) {}

    /** Build from an active VoiceProfile (tone_axes + audience.primary + persona.credibility). */
    public static function fromVoiceProfile(VoiceProfile $voice): self
    {
        return new self(
            formality: (float) data_get($voice->tone_axes, 'formality', 0.5),
            warmth: (float) data_get($voice->tone_axes, 'warmth', 0.6),
            audience: (string) data_get($voice->audience, 'primary', ''),
            credibility: (string) data_get($voice->persona, 'credibility', ''),
        );
    }
}
