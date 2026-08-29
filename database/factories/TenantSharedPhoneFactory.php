<?php

namespace Database\Factories;

use App\Enums\SharedPhonePurpose;
use App\Models\Site;
use App\Models\TenantSharedPhone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantSharedPhone>
 */
class TenantSharedPhoneFactory extends Factory
{
    protected $model = TenantSharedPhone::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'phone' => $this->faker->numerify('877-###-####'),
            'purpose' => SharedPhonePurpose::Corporate,
            'owning_location_id' => null,
        ];
    }
}
