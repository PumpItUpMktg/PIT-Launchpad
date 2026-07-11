<?php

namespace Database\Factories;

use App\Enums\BlogTargetStatus;
use App\Models\BlogTarget;
use App\Models\Keyword;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlogTarget>
 */
class BlogTargetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'silo_id' => Silo::factory(),
            'keyword_id' => Keyword::factory(),
            'status' => BlogTargetStatus::Queued,
            'article_ref' => null,
            'queued_at' => now(),
        ];
    }
}
