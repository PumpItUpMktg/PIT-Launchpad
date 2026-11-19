<?php

namespace App\Enums;

/** What put a share in the pipeline — the publish-day shares, the re-share rules, or an operator. */
enum SocialTrigger: string
{
    case JobPublished = 'job_published';
    case PostPublished = 'post_published';
    case PostRefreshed = 'post_refreshed';
    case Momentum = 'momentum';
    case Weather = 'weather';
    case Manual = 'manual';
    case Planned = 'planned';   // laid out ahead on the board's planner: a re-share with a date

    public function label(): string
    {
        return match ($this) {
            self::JobPublished => 'Job published',
            self::PostPublished => 'Post published',
            self::PostRefreshed => 'Post refreshed',
            self::Momentum => 'Upward movement',
            self::Weather => 'Weather',
            self::Manual => 'Manual',
            self::Planned => 'Planned',
        };
    }
}
