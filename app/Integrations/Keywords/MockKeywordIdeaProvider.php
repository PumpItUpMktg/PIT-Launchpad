<?php

namespace App\Integrations\Keywords;

use App\Integrations\DataForSeo\KeywordIdea;
use App\Models\Site;

/**
 * Deterministic keyword-idea source for tests / offline runs — expands each seed into the seed itself
 * plus a fixed set of commercial/transactional/informational/geo modifiers, with volumes derived
 * deterministically from the term so a run is reproducible without network. The geo modifier ("… near
 * me") is included on purpose so the corpus builder's geo filter has something to strip.
 */
final class MockKeywordIdeaProvider implements KeywordIdeaProvider
{
    /** modifier => volume divisor (lower divisor = higher volume). */
    private const MODIFIERS = [
        '' => 1,            // the seed itself
        'cost' => 2,
        'installation' => 2,
        'repair' => 3,
        'services' => 4,
        'how to' => 6,      // informational
        'near me' => 5,     // geo — filtered out downstream
    ];

    public function ideas(Site $site, string $seed, int $limit): array
    {
        $base = 5000 + (abs(crc32($seed)) % 3000); // deterministic per-seed base volume
        $ideas = [];
        foreach (self::MODIFIERS as $modifier => $divisor) {
            $term = trim($modifier === 'how to' ? "how to {$seed}" : "{$seed} {$modifier}");
            $ideas[] = new KeywordIdea(
                keyword: $term,
                volume: intdiv($base, $divisor),
                competition: round((abs(crc32($term)) % 100) / 100, 2),
                difficulty: abs(crc32($term)) % 100,
            );
        }

        return array_slice($ideas, 0, max(1, $limit));
    }
}
