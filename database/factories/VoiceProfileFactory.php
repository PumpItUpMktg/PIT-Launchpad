<?php

namespace Database\Factories;

use App\Enums\VoiceStatus;
use App\Models\Site;
use App\Models\VoiceProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VoiceProfile>
 */
class VoiceProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'version' => 1,
            'status' => VoiceStatus::Draft,
            'framing_model' => 'problem_solution',
            'tone_axes' => ['formality' => 0.4, 'warmth' => 0.7],
            'reading_level' => 'grade_8',
            'jargon_policy' => 'minimal',
            'format_conventions' => ['oxford_comma' => true],
            'language_rules' => ['preferred' => ['repair'], 'banned' => ['cheap']],
            'audience' => ['primary' => 'homeowners'],
            'cta_voice' => 'direct',
            'persona' => ['perspective' => 'we', 'identity' => 'local expert'],
            'calibration_refs' => [],
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => VoiceStatus::Active]);
    }
}
