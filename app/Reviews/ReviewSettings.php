<?php

namespace App\Reviews;

use App\Models\Site;

/**
 * Per-tenant Review Capture settings (§6), each falling back to the config default when the tenant hasn't set
 * an override.
 */
final class ReviewSettings
{
    public function bodyMinLength(Site $site): int
    {
        return $site->review_body_min_length ?? (int) config('reviews.body_min_length', 20);
    }

    public function remindersEnabled(Site $site): bool
    {
        return (bool) ($site->review_reminders_enabled ?? true);
    }
}
