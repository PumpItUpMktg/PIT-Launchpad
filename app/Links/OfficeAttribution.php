<?php

namespace App\Links;

use App\Models\LinkProspect;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Which office a link prospect belongs to (§ Links). A local opportunity is listed under the office whose
 * search found it; a state-wide one (an association, a program — found by a state search, no office
 * behind it) is attributed to the corporate location only, so a multi-office business approaches it once.
 * Competitor-link prospects (the backlink intersect) have no office and sit last.
 */
final class OfficeAttribution
{
    public const CORPORATE = 'corporate';

    public const COMPETITORS = 'competitors';

    /**
     * @param  Collection<int, LinkProspect>  $prospects
     * @return array<string, array{key: string, label: string, order: int}> keyed by prospect id
     */
    public function for(Site $site, Collection $prospects): array
    {
        $offices = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->orderBy('created_at')->get(['id', 'name'])
            ->values()->mapWithKeys(fn (Location $l, int $i): array => [(string) $l->id => ['key' => (string) $l->id, 'label' => trim((string) $l->name) !== '' ? (string) $l->name : 'Office', 'order' => $i]])->all();
        $corporate = ['key' => self::CORPORATE, 'label' => self::corporateLabel($site), 'order' => 1000];
        $competitors = ['key' => self::COMPETITORS, 'label' => 'Competitor links (no office — sites linking to competitors near you)', 'order' => 2000];

        $out = [];
        foreach ($prospects as $p) {
            if (! $p->kind->isLane()) {
                $out[(string) $p->id] = $competitors;
            } elseif ($p->location_id !== null && isset($offices[(string) $p->location_id])) {
                $out[(string) $p->id] = $offices[(string) $p->location_id];
            } else {
                $out[(string) $p->id] = $corporate;
            }
        }

        return $out;
    }

    public static function corporateLabel(Site $site): string
    {
        $where = trim(implode(', ', array_filter([trim((string) $site->corporate_city), trim((string) $site->corporate_state)])));

        return 'Corporate'.($where !== '' ? " ({$where})" : '').' · state-wide organisations, approached once by the business';
    }
}
