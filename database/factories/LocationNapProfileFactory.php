<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LocationNapProfile>
 */
class LocationNapProfileFactory extends Factory
{
    protected $model = LocationNapProfile::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $site = Site::factory();

        return [
            'site_id' => $site,
            'location_id' => Location::factory()->for($site),
            'business_name' => $this->faker->company(),
            'address_1' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'state' => 'NJ',
            'postal' => $this->faker->postcode(),
            'phone_primary' => $this->faker->numerify('973-###-####'),
            'website_url' => $this->faker->url(),
            'verification_email' => $this->faker->safeEmail(),
        ];
    }
}
