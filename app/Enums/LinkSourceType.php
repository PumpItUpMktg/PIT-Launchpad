<?php

namespace App\Enums;

/**
 * The link-plan sources, in descending value — the origin of a proposed inbound link. Ordered so the
 * strongest editorial links (a nearby indexed page carrying a real job/review) rank above the weakest
 * (the Areas-We-Serve directory).
 *
 * All but one exist to get a NEW page discovered. `Strengthen` is the exception: it links a page that is
 * already indexed and already ranking, to move it up rather than to find it at all.
 */
enum LinkSourceType: string
{
    case JobReview = 'job_review';   // (4) an indexed page carrying a job/review in the town links back — strongest
    case Market = 'market';          // (1) the market landing page → each new town (parent → child)
    case Strengthen = 'strengthen';  // (3) an indexed same-silo page → an indexed page in striking distance
    case Mesh = 'mesh';              // (2) indexed neighbouring town pages → new town (geographic adjacency)
    case Blog = 'blog';              // (4) a blog post mentioning the town → the town page
    case Areas = 'areas';            // (5) the Areas-We-Serve page → all towns (discovery only, low value)

    public function label(): string
    {
        return match ($this) {
            self::JobReview => 'Job/review back-link',
            self::Market => 'Market page',
            self::Strengthen => 'Same-silo strengthen',
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
            // Topical relevance chosen for opportunity beats geographic proximity, which is why mesh sits
            // below it. The relative order of every pre-existing source is unchanged.
            self::Strengthen => 2,
            self::Mesh => 3,
            self::Blog => 4,
            self::Areas => 5,
        };
    }
}
