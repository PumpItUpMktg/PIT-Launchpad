<?php

namespace Database\Factories;

use App\Models\Directory;
use App\Models\Site;
use App\Models\TenantDirectoryExclusion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantDirectoryExclusion>
 */
class TenantDirectoryExclusionFactory extends Factory
{
    protected $model = TenantDirectoryExclusion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'directory_id' => Directory::factory(),
            'reason' => 'Not relevant to this trade',
            'excluded_at' => now(),
        ];
    }
}
