<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $brand = fake()->unique()->company();

        return [
            'account_id' => Account::factory(),
            'brand_name' => $brand,
            'legal_name' => $brand.' LLC',
            'dba' => null,
            'tagline' => rtrim(fake()->sentence(4), '.'),
            'domain_url' => 'https://'.Str::slug($brand).'.com',
            'slug_conventions' => ['pattern' => '{service}-{city}'],
            'status' => 'active',
        ];
    }
}
