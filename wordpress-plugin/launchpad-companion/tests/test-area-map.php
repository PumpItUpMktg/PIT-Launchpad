<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Render\AreaMap;

class Test_Area_Map extends WP_UnitTestCase
{
    /** A county ring plus two towns — the shape the control plane pushes. */
    private function payload(): array
    {
        return [
            'counties' => [[
                'name' => 'Bucks County',
                'rings' => [[
                    ['lat' => 40.6, 'lng' => -75.2],
                    ['lat' => 40.6, 'lng' => -74.9],
                    ['lat' => 40.2, 'lng' => -74.9],
                    ['lat' => 40.2, 'lng' => -75.2],
                    ['lat' => 40.6, 'lng' => -75.2],
                ]],
            ]],
            'cities' => [
                ['name' => 'Doylestown', 'lat' => 40.31, 'lng' => -75.13, 'tier' => 'large', 'url' => 'https://spg.example/doylestown-pa/'],
                ['name' => 'Warrington', 'lat' => 40.25, 'lng' => -75.15, 'tier' => 'medium', 'url' => ''],
            ],
        ];
    }

    public function test_draws_the_counties_towns_and_links_without_any_tiles(): void
    {
        $svg = AreaMap::svg($this->payload());

        $this->assertStringContainsString('<svg class="lp-map-svg"', $svg);
        $this->assertStringContainsString('viewBox="0 0 1000 700"', $svg);
        $this->assertStringContainsString('class="lp-map-county"', $svg);
        $this->assertSame(2, substr_count($svg, 'class="lp-map-town"'));

        // A town with a page is a real link — crawlable, not a JS click handler.
        $this->assertStringContainsString('<a href="https://spg.example/doylestown-pa/"', $svg);
        $this->assertSame(1, substr_count($svg, 'lp-map-link'));

        // Names ride as <title> so the shapes are labelled for hover AND for a screen reader.
        $this->assertStringContainsString('<title>Bucks County</title>', $svg);

        // No tile server, no key, no script: that is the whole point of the change.
        $this->assertStringNotContainsString('cartocdn', $svg);
        $this->assertStringNotContainsString('<script', $svg);
    }

    public function test_north_is_up_and_the_drawing_stays_inside_the_canvas(): void
    {
        $svg = AreaMap::svg($this->payload());

        preg_match_all('/<circle cx="([\d.]+)" cy="([\d.]+)"/', $svg, $m);
        $this->assertCount(2, $m[1]);

        // Doylestown (40.31) is north of Warrington (40.25), so it must sit HIGHER — a smaller y.
        $this->assertLessThan((float) $m[2][1], (float) $m[2][0]);

        foreach ($m[1] as $i => $x) {
            $this->assertGreaterThanOrEqual(0, (float) $x);
            $this->assertLessThanOrEqual(1000, (float) $x);
            $this->assertGreaterThanOrEqual(0, (float) $m[2][$i]);
            $this->assertLessThanOrEqual(700, (float) $m[2][$i]);
        }
    }

    public function test_renders_nothing_rather_than_an_empty_figure(): void
    {
        $this->assertSame('', AreaMap::svg([]));
        $this->assertSame('', AreaMap::svg(['counties' => [], 'cities' => []]));
        // One point is a dot on a blank field, not a map.
        $this->assertSame('', AreaMap::svg(['cities' => [['lat' => 40.3, 'lng' => -75.1]]]));
    }

    public function test_a_pin_joins_the_geometry_and_is_labelled(): void
    {
        $payload = $this->payload();
        $payload['pin'] = ['lat' => 40.3, 'lng' => -75.1, 'label' => 'Our shop'];

        $svg = AreaMap::svg($payload);

        $this->assertStringContainsString('class="lp-map-pin"', $svg);
        $this->assertStringContainsString('<title>Our shop</title>', $svg);
    }
}
