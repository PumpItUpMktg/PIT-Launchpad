<?php

namespace App\ContentEngine\Geography;

use App\Integrations\Claude\ClaudeClient;

/**
 * One narrow (Haiku) call: the place a headline is anchored to. Used by the read-only geography report on
 * candidates ingested BEFORE the scorer started stamping `meta.story_place` — the live funnel gets the same
 * two fields from the relevance call itself, at no extra cost.
 */
class PlaceExtractor
{
    public function __construct(private readonly ClaudeClient $claude) {}

    /**
     * @return array{place: ?string, state: ?string} "Omaha, NE" + "NE", or nulls for a placeless piece
     */
    public function extract(string $title, string $sourceName = '', ?string $summary = null): array
    {
        $prompt = "Where is this news item anchored?\n\nTitle: {$title}\n"
            .($summary !== null && trim($summary) !== '' ? "Summary: {$summary}\n" : '')
            .($sourceName !== '' ? "Outlet: {$sourceName}\n" : '')
            ."\nReturn ONLY JSON: {\"story_place\":\"<City, ST or null>\",\"story_state\":\"<2-letter US state or null>\"}. "
            .'story_place is the place the event happened or the municipality/region/state program the piece is about; '
            .'null for a placeless how-to, a product roundup or a national piece. Never infer a place from the outlet alone '
            .'(a Detroit newspaper carrying a national press release is not a Michigan story); a non-US place gets '
            .'story_state null and the country in story_place.';

        $raw = $this->claude->complete($prompt, 'You extract the geographic anchor of news headlines. Return strict JSON only.');
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        $data = ($start !== false && $end !== false && $end >= $start) ? json_decode(substr($raw, $start, $end - $start + 1), true) : null;
        $data = is_array($data) ? $data : [];

        $place = isset($data['story_place']) && is_string($data['story_place']) && trim($data['story_place']) !== '' ? trim($data['story_place']) : null;
        $state = isset($data['story_state']) && is_string($data['story_state']) && trim($data['story_state']) !== '' ? strtoupper(trim($data['story_state'])) : null;

        return ['place' => $place, 'state' => $state];
    }
}
