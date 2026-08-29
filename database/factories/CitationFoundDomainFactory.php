<?php

namespace Database\Factories;

use App\Models\CitationFoundDomain;
use App\Models\Location;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CitationFoundDomain>
 */
class CitationFoundDomainFactory extends Factory
{
    protected $model = CitationFoundDomain::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $site = Site::factory();
        $domain = $this->faker->domainName();

        return [
            'site_id' => $site,
            'location_id' => Location::factory()->for($site),
            'domain' => $domain,
            'directory_id' => null,
            'found_url' => 'https://'.$domain.'/'.$this->faker->slug(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
