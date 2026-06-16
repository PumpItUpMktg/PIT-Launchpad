<?php

namespace Tests\Support;

/**
 * A representative SPG-shaped candidate tree for exercising the Phase 2 expansion
 * plumbing (parse → validate → build → persist) without a live model call.
 */
final class ExpansionFixture
{
    /**
     * @return array<string, mixed>
     */
    public static function tree(): array
    {
        return [
            'silos' => [
                [
                    'name' => 'Sump Pumps',
                    'head_keyword' => 'sump pump',
                    'page_type' => 'service',
                    'spokes' => [
                        ['name' => 'Sump Pump Installation', 'page_type' => 'service', 'tag' => 'core', 'head_keyword' => 'sump pump installation', 'connection_note' => null, 'granularity' => 'own_page'],
                        ['name' => 'Sump Pump Replacement', 'page_type' => 'service', 'tag' => 'core', 'head_keyword' => 'sump pump replacement', 'connection_note' => null, 'granularity' => 'own_page'],
                        ['name' => 'Sump Pump Repair (Any Brand)', 'page_type' => 'service', 'tag' => 'core', 'head_keyword' => 'sump pump repair', 'connection_note' => null, 'granularity' => 'own_page'],
                        ['name' => 'Battery Backup Systems', 'page_type' => 'service', 'tag' => 'adjacent', 'head_keyword' => 'sump pump battery backup', 'connection_note' => null, 'granularity' => 'own_page'],
                        ['name' => 'Why Is My Basement Wet?', 'page_type' => 'content', 'tag' => 'adjacent', 'head_keyword' => 'why is my basement wet', 'connection_note' => null, 'granularity' => 'own_page'],
                    ],
                ],
                [
                    'name' => 'Waterproofing & Drainage',
                    'head_keyword' => 'basement waterproofing',
                    'page_type' => 'service',
                    'spokes' => [
                        ['name' => 'Gutter Installation', 'page_type' => 'service', 'tag' => 'connecting', 'head_keyword' => 'gutter installation', 'connection_note' => 'gutters — a cause of basement water', 'granularity' => 'own_page'],
                        ['name' => 'French Drains', 'page_type' => 'service', 'tag' => 'connecting', 'head_keyword' => 'french drain installation', 'connection_note' => 'drainage diverts groundwater from the foundation', 'granularity' => 'own_page'],
                    ],
                ],
                [
                    'name' => 'Commercial & Industrial',
                    'head_keyword' => 'commercial pump services',
                    'page_type' => 'service',
                    'spokes' => [
                        ['name' => 'Commercial Grinder Pumps', 'page_type' => 'service', 'tag' => 'core', 'head_keyword' => 'commercial grinder pump', 'connection_note' => null, 'granularity' => 'own_page'],
                    ],
                ],
                [
                    'name' => 'Brands We Service',
                    'head_keyword' => 'pump brands',
                    'page_type' => 'service',
                    'spokes' => [
                        ['name' => 'Liberty Pumps', 'page_type' => 'service', 'tag' => 'core', 'head_keyword' => 'liberty pumps', 'connection_note' => null, 'granularity' => 'own_page'],
                        ['name' => 'Zoeller', 'page_type' => 'service', 'tag' => 'core', 'head_keyword' => 'zoeller pumps', 'connection_note' => null, 'granularity' => 'own_page'],
                    ],
                ],
            ],
            'fringe_handoff' => [
                ['name' => 'General Plumbing', 'connection_note' => 'adjacent trade, out of the waterproofing lane', 'sibling_brand' => null],
                ['name' => 'Mold Remediation', 'connection_note' => 'mold from chronic basement moisture', 'sibling_brand' => 'Trusted Mold'],
            ],
        ];
    }

    public static function json(): string
    {
        return (string) json_encode(self::tree());
    }
}
