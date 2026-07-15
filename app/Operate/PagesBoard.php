<?php

namespace App\Operate;

use App\Guided\GrowDashboard;
use App\Guided\LiveBoards;
use App\Models\Site;

/**
 * The per-family Pages boards (operate relay, part 2): the FULL page lifecycle on one surface per
 * family — Core / Service / Location — composing the two proven read models instead of writing a
 * third: the work section is {@see GrowDashboard::sections()}'s matching lane (everything not yet
 * published, most-actionable-first, morphing primary), and the live section is the matching
 * {@see LiveBoards} board (published cards with tracking). Membership stays state-driven, so a
 * page "moves" between the two sections of ITS OWN board by status alone.
 */
class PagesBoard
{
    public function __construct(
        private readonly GrowDashboard $grow,
        private readonly LiveBoards $live,
    ) {}

    /**
     * @return array{work: list<array<string, mixed>>, live: list<array<string, mixed>>}
     */
    public function core(Site $site): array
    {
        return ['work' => $this->workLane($site, 'core'), 'live' => $this->live->core($site)];
    }

    /**
     * @return array{work: list<array<string, mixed>>, live: list<array<string, mixed>>}
     */
    public function services(Site $site): array
    {
        return ['work' => $this->workLane($site, 'service'), 'live' => $this->live->services($site)];
    }

    /**
     * Locations keeps the live side GROUPED (location card + its towns + city-service pages),
     * exactly like the Live board it supersedes.
     *
     * @return array{work: list<array<string, mixed>>, live: array{groups: list<array<string, mixed>>, orphans: list<array<string, mixed>>, location_options: array<string, string>}}
     */
    public function locations(Site $site): array
    {
        return ['work' => $this->workLane($site, 'town'), 'live' => $this->live->locations($site)];
    }

    /** The site-level data-source chips for the live cards. */
    public function sources(Site $site): array
    {
        return $this->live->sources($site);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function workLane(Site $site, string $key): array
    {
        foreach ($this->grow->sections($site) as $section) {
            if ($section['key'] === $key) {
                return $section['pages'];
            }
        }

        return [];
    }
}
