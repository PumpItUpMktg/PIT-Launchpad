<?php

namespace Tests\Support;

/**
 * Builders for drafter-output fixtures: the sentinel-delimited blocks the Sonnet
 * seam now returns, assembled so individual tests can override just the part they
 * exercise. The logical payload shape is unchanged from the old JSON fixtures —
 * only the wire encoding (via {@see SentinelEncoder}) differs, so the drafting
 * tests still assert on parsed results, not on the format.
 */
class Draft
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function json(array $overrides = []): string
    {
        $payload = array_merge([
            'seo' => [
                'title' => 'Tankless Water Heater Rebates Explained',
                'meta_description' => 'What the new rebate means for your home and how to claim it.',
                'slug' => 'tankless-water-heater-rebates',
                'og_title' => 'Tankless Rebates',
                'og_description' => 'A homeowner-friendly rundown.',
            ],
            'images' => [],
            'claims_used' => [],
            'sources_cited' => [],
            'towns' => [],
        ], $overrides);

        return SentinelEncoder::encode($payload);
    }

    /**
     * A complete post draft (body) with one cited claim and one source.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function post(string $claimId, array $overrides = []): string
    {
        return self::json(array_merge([
            'body' => '<p>Worried about your old water heater? Here is the fix.</p>',
            'claims_used' => [
                ['text' => 'We back every install with a 10-year warranty.', 'claim_id' => $claimId],
            ],
            'sources_cited' => [
                ['name' => 'Local Tribune', 'url' => 'https://localtribune.example/rebate-story'],
            ],
        ], $overrides));
    }
}
