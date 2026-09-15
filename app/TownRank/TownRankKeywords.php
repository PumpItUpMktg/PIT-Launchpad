<?php

namespace App\TownRank;

use App\Enums\KeywordSource;
use App\Jobs\RunTownRankKeyword;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankScan;
use InvalidArgumentException;

/**
 * The operator's keyword affordances on the Town Rank wall (§ Town Rank): TRACK a keyword (reuse the site's
 * existing keyword by exact query, else create it) so it gets a card and joins the weekly sweep, and RUN a
 * ranking report for one keyword now — both query modes, posted on the queue, collected by the ingest sweep —
 * with the cost estimate and the same hard ceiling as the sweep. A keyword with a scan still collecting is
 * never re-posted.
 */
final class TownRankKeywords
{
    public function __construct(private readonly TownRankPoints $points) {}

    /**
     * @throws InvalidArgumentException when the query is blank
     */
    public function track(Site $site, string $query): Keyword
    {
        $text = trim(preg_replace('/\s+/', ' ', $query) ?? $query);
        if ($text === '') {
            throw new InvalidArgumentException('Enter a keyword.');
        }

        $keyword = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereRaw('lower(trim(query)) = ?', [mb_strtolower($text)])
            ->first();
        $keyword ??= Keyword::create([
            'site_id' => $site->id,
            'query' => $text,
            'source' => KeywordSource::Seed,
            'status' => 'candidate',
        ]);
        if (! $keyword->track_town_rank) {
            $keyword->forceFill(['track_town_rank' => true])->save();
        }

        return $keyword;
    }

    /**
     * What a run for this keyword would post: one organic task per covered town per mode not already
     * collecting.
     *
     * @return array{towns: int, modes: list<string>, requests: int, cost: float, ceiling: int, over_ceiling: bool, pending: bool}
     */
    public function estimate(Site $site, Keyword $keyword): array
    {
        $towns = count($this->points->forSite($site));
        $modes = [];
        $pending = false;
        foreach (TownRankScan::MODES as $mode) {
            $latest = TownRankScan::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('keyword_id', $keyword->id)->where('mode', $mode)
                ->orderByDesc('scanned_at')->first();
            if ($latest !== null && $latest->status === 'pending') {
                $pending = true;

                continue;
            }
            $modes[] = $mode;
        }
        $requests = $towns * count($modes);
        $ceiling = max(0, (int) config('launchpad.town_rank.request_ceiling', 2000));

        return [
            'towns' => $towns,
            'modes' => $modes,
            'requests' => $requests,
            'cost' => $requests * (float) config('launchpad.town_rank.cost_per_request', 0.0012),
            'ceiling' => $ceiling,
            'over_ceiling' => $ceiling > 0 && $requests > $ceiling,
            'pending' => $pending,
        ];
    }

    /**
     * Queue the run (both modes not already collecting). Returns why when nothing was queued.
     *
     * @return array{queued: bool, reason: string|null, requests: int, cost: float}
     */
    public function run(Site $site, Keyword $keyword): array
    {
        $e = $this->estimate($site, $keyword);
        $reason = match (true) {
            $e['towns'] === 0 => 'No covered towns to scan yet — a location needs served counties or assigned towns.',
            $e['modes'] === [] => 'A report is already collecting for this keyword — results land within a few minutes.',
            $e['over_ceiling'] => sprintf('%s requests exceeds the hard ceiling (%s). Raise LAUNCHPAD_TOWN_RANK_REQUEST_CEILING or narrow the footprint.', number_format($e['requests']), number_format($e['ceiling'])),
            default => null,
        };
        if ($reason !== null) {
            return ['queued' => false, 'reason' => $reason, 'requests' => $e['requests'], 'cost' => $e['cost']];
        }

        RunTownRankKeyword::dispatch((string) $site->id, (string) $keyword->id);

        return ['queued' => true, 'reason' => null, 'requests' => $e['requests'], 'cost' => $e['cost']];
    }
}
