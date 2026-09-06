<?php

namespace App\Enums;

/**
 * One of the two ORTHOGONAL classification axes stamped on a news candidate at ingestion (the other is
 * {@see ShelfLife}). Scope is about PLACE — is the hook anchored to the brand's service area?
 *
 * - General: a topic with no specific geography — advisory content anyone in the trade could run.
 * - Local: anchored to a specific place in/near the brand's service area — the most actionable, geo-targeted
 *   hook.
 *
 * Independent of {@see ShelfLife}: a local story can be topical (a flood this week) or evergreen (a town's
 * chronic water-table problem). Derived from the §6a RelevanceScorer's `local_relevance` signal.
 */
enum CandidateScope: string
{
    case General = 'general';
    case Local = 'local';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::Local => 'Local',
        };
    }

    public static function fromLocal(bool $local): self
    {
        return $local ? self::Local : self::General;
    }
}
