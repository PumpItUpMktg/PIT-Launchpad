<?php

namespace Database\Factories;

use App\Enums\WizardStep;
use App\Models\OnboardingState;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnboardingState>
 */
class OnboardingStateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'current_step' => WizardStep::Account,
            'completed_steps' => [],
        ];
    }
}
