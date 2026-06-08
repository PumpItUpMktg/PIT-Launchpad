<?php

namespace App\Integrations\Voice;

/**
 * Deterministic voice synthesis mock for tests and the default binding. Drafts a
 * VoiceProfile payload from the interview answers.
 */
class MockVoiceSynthesizer implements VoiceSynthesizer
{
    /**
     * @param  array<string, mixed>  $interview
     * @return array<string, mixed>
     */
    public function synthesize(array $interview): array
    {
        return [
            'framing_model' => 'problem_solution',
            'tone_axes' => [
                'formality' => (float) ($interview['formality'] ?? 0.4),
                'warmth' => (float) ($interview['warmth'] ?? 0.7),
            ],
            'reading_level' => $interview['reading_level'] ?? 'grade_8',
            'persona' => [
                'perspective' => 'we',
                'identity' => $interview['identity'] ?? 'local expert',
                'credibility' => $interview['credibility'] ?? 'licensed and insured',
            ],
            'language_rules' => [
                'preferred' => $interview['preferred_terms'] ?? [],
                'banned' => $interview['banned_terms'] ?? [],
            ],
            'audience' => ['primary' => $interview['audience'] ?? 'homeowners'],
            'cta_voice' => $interview['cta_voice'] ?? 'direct',
        ];
    }
}
