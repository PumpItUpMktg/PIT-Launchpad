<?php

namespace App\Enums;

/**
 * The five link-plan sources, in descending value — the origin of a proposed inbound link to a newly-built
 * town page. Ordered so the strongest editorial links (a nearby indexed page carrying a real job/review)
 * rank above the weakest (the Areas-We-Serve directory).
 */
enum LinkSourceType: string
{
    case JobReview = 'job_review';   // (4) an indexed page carrying a job/review in the town links back — strongest
    case Market = 'market';          // (1) the market landing page → each new town (parent → child)
    case Mesh = 'mesh';              // (2) indexed neighbouring town pages → new town (geographic adjacency)
    case Blog = 'blog';              // (3) a blog post mentioning the town → the town page
    case Areas = 'areas';            // (5) the Areas-We-Serve page → all towns (discovery only, low value)

    public function label(): string
    {
        return match ($this) {
            self::JobReview => 'Job/review back-link',
            self::Market => 'Market page',
            self::Mesh => 'Neighbouring town',
            self::Blog => 'Blog mention',
            self::Areas => 'Areas We Serve',
        };
    }

    /** Rank for ordering proposals strongest-first (lower = stronger). */
    public function rank(): int
    {
        return match ($this) {
            self::JobReview => 0,
            self::Market => 1,
            self::Mesh => 2,
            self::Blog => 3,
            self::Areas => 4,
        };
    }
}
