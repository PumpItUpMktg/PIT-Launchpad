<?php

namespace Database\Factories;

use App\Enums\AcquisitionType;
use App\Enums\DirectoryScope;
use App\Enums\MultiLocationPolicy;
use App\Models\Directory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Directory>
 */
class DirectoryFactory extends Factory
{
    protected $model = Directory::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'domain' => $this->faker->unique()->domainName(),
            'name' => $this->faker->company().' Directory',
            'scope' => DirectoryScope::National,
            'geo_value' => null,
            'trade_categories' => ['plumbing'],
            'authority_tier' => $this->faker->numberBetween(1, 5),
            'acquisition_type' => AcquisitionType::Free,
            'multi_location_policy' => MultiLocationPolicy::OnePerAddress,
            'requires_client_action' => false,
            'domain_rank' => $this->faker->numberBetween(0, 100),
            'seo_value' => $this->faker->numberBetween(0, 100),
            'business_value' => $this->faker->numberBetween(0, 100),
            'is_nofollow' => false,
            'is_active' => true,
        ];
    }

    public function paid(float $amount = 12.0): static
    {
        return $this->state(fn (): array => [
            'acquisition_type' => AcquisitionType::PaidOneTime,
            'cost_amount' => $amount,
            'cost_period' => 'one_time',
        ]);
    }

    public function geo(DirectoryScope $scope, string $geoValue): static
    {
        return $this->state(fn (): array => ['scope' => $scope, 'geo_value' => $geoValue]);
    }
}
