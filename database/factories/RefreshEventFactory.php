<?php

namespace Database\Factories;

use App\Enums\RefreshTrigger;
use App\Models\Content;
use App\Models\RefreshEvent;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RefreshEvent>
 */
class RefreshEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'content_id' => Content::factory(),
            'trigger' => RefreshTrigger::Manual,
            'note' => null,
        ];
    }
}
