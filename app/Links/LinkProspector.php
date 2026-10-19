<?php

namespace App\Links;

use App\Backlinks\BacklinkSyncer;
use App\Citations\LinkEvidence;
use App\Enums\CompetitorClass;
use App\Enums\LinkProspectKind;
use App\Enums\LinkProspectStatus;
use App\Integrations\Backlinks\BacklinkProvider;
use App\KeywordGenerator\Beatability\CompetitorClassifier;
use App\Models\Directory;
use App\Models\LinkProspect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * The competitor link intersect (§ Links): ask the backlink index for every domain that links to the site's
 * local competitors (CompetitorTargets) and not to the site, and keep the ones worth a human's time as
 * link_prospects — ranked by how many competitors they link to, then by authority. Junk is dropped at the
 * door (spam at or above the cap, authority below the floor). A prospect already on the list keeps its
 * status and notes; only the index fields refresh. Never contacts anyone, never submits anything.
 */
final class LinkProspector
{
    public function __construct(
        private readonly BacklinkProvider $provider,
        private readonly CompetitorTargets $targets,
        private readonly CompetitorClassifier $classifier,
    ) {}

    /**
     * @return array{targets: list<array{domain: string, appearances: int}>, returned: int, kept: int, new: int, refreshed: int, pruned: int}
     */
    public function run(Site $site): array
    {
        $targets = $this->targets->for($site, max(1, (int) config('launchpad.links.competitor_targets', 10)));
        if ($targets === []) {
            return ['targets' => [], 'returned' => 0, 'kept' => 0, 'new' => 0, 'refreshed' => 0, 'pruned' => 0];
        }

        $host = BacklinkSyncer::target((string) $site->domain_url);
        $rows = $this->provider->domainIntersection(
            array_column($targets, 'domain'),
            $host !== '' ? [$host] : [],
            max(1, (int) config('launchpad.links.intersect_limit', 300)),
        );

        $minRank = (int) config('launchpad.links.min_rank', 10);
        $spamCap = (int) config('launchpad.links.spam_cap', 30);
        $directoryDomains = Directory::query()->where('is_active', true)->pluck('domain')->map('strval')->all();
        $existing = LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()->keyBy('domain');

        $kept = $new = $refreshed = 0;
        $now = now();
        foreach ($rows as $row) {
            if ($row->domain === $host || ($row->rank !== null && $row->rank < $minRank) || ($row->spamScore !== null && $row->spamScore >= $spamCap)) {
                continue;
            }
            $kept++;
            $competitors = array_values(array_unique($row->linkedTargets));
            $fields = [
                'rank' => $row->rank,
                'spam_score' => $row->spamScore,
                'competitors' => $competitors,
                'competitor_count' => count($competitors),
                'kind' => $this->kind($row->domain, $directoryDomains),
                'first_seen' => $row->firstSeen,
                'found_at' => $now,
            ];
            $current = $existing->get($row->domain);
            if ($current !== null) {
                $current->forceFill($fields)->save();
                $refreshed++;

                continue;
            }
            LinkProspect::withoutGlobalScope(SiteScope::class)->create(array_merge($fields, [
                'site_id' => $site->id, 'domain' => $row->domain, 'status' => LinkProspectStatus::New,
            ]));
            $new++;
        }

        // A prospect nobody has touched (still `new`) that this run did not return was an artefact of an
        // earlier, worse competitor set: drop it. Contacted / acquired / dismissed rows are the operator's.
        $pruned = LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('status', LinkProspectStatus::New->value)->where('found_at', '<', $now)->delete();

        return ['targets' => $targets, 'returned' => count($rows), 'kept' => $kept, 'new' => $new, 'refreshed' => $refreshed, 'pruned' => $pruned];
    }

    /** @param  list<string>  $directoryDomains */
    private function kind(string $domain, array $directoryDomains): LinkProspectKind
    {
        foreach ($directoryDomains as $dir) {
            if (LinkEvidence::onDomain($domain, $dir)) {
                return LinkProspectKind::Directory;
            }
        }

        return match ($this->classifier->classify($domain)) {
            CompetitorClass::AggregatorDirectory => LinkProspectKind::Directory,
            CompetitorClass::EditorialGov => LinkProspectKind::Editorial,
            CompetitorClass::NationalBigBox => LinkProspectKind::National,
            default => LinkProspectKind::Local,
        };
    }
}
