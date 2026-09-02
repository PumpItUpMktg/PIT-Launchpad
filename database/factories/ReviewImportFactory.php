<?php

namespace Database\Factories;

use App\Models\ReviewImport;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewImport>
 */
class ReviewImportFactory extends Factory
{
    protected $model = ReviewImport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'status' => 'pending',
            'source' => 'csv',
            'filename' => 'reviews.csv',
        ];
    }
}
