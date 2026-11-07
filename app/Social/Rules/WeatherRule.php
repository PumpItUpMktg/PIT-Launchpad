<?php

namespace App\Social\Rules;

use App\Enums\SocialNetwork;
use App\Enums\SocialSuggestionStatus;
use App\Enums\SocialTrigger;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SocialChannel;
use App\Models\SocialSuggestion;
use App\Operate\RainfallSeries;
use App\Social\SocialSharer;
use App\Social\SuggestionBoard;
use Illuminate\Support\Carbon;

/**
 * Heavy rain → an automatic re-share, never the same post twice. Fires when the site's observed rainfall
 * (the NOAA series the rainfall board already pulls, averaged across the site's stations) reaches
 * `inches_1d` in the latest day or `inches_2d` over the latest two. One share per rain event: a storm that
 * keeps the series above threshold for days fires once (`event_days`). Per GBP location the pool is drawn
 * separately ({@see RainPool}); the Pages get one draw. The storm line that opens the copy is drawn from
 * `leads` and the chosen one recorded, so the next storm opens differently. No model call.
 */
final class WeatherRule
{
    public function __construct(
        private readonly RainfallSeries $rain,
        private readonly RainPool $pool,
        private readonly SocialSharer $sharer,
        private readonly SuggestionBoard $board,
    ) {}

    /** @return array{fired: bool, inches_1d: float, inches_2d: float, date: string|null, shares: list<array{post: string, networks: list<string>, location: string|null, lead: string}>, skipped: string|null} */
    public function run(Site $site, bool $execute = true): array
    {
        $cfg = (array) config('launchpad.social.weather', []);
        $out = ['fired' => false, 'inches_1d' => 0.0, 'inches_2d' => 0.0, 'date' => null, 'shares' => [], 'skipped' => null];
        $series = $this->rain->daily($site, Carbon::now()->subDays(4));
        if ($series === []) {
            $out['skipped'] = 'no rainfall series';

            return $out;
        }
        ksort($series);
        $dates = array_keys($series);
        $latest = (string) end($dates);
        $prev = count($dates) >= 2 ? $dates[count($dates) - 2] : null;
        $out['date'] = $latest;
        $out['inches_1d'] = (float) $series[$latest];
        $out['inches_2d'] = (float) $series[$latest] + ($prev !== null ? (float) $series[$prev] : 0.0);
        if ($out['inches_1d'] < (float) ($cfg['inches_1d'] ?? 1.0) && $out['inches_2d'] < (float) ($cfg['inches_2d'] ?? 1.5)) {
            return $out;
        }
        $out['fired'] = true;

        // One share per rain event.
        $eventDays = (int) ($cfg['event_days'] ?? 3);
        $already = SocialSuggestion::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('trigger', SocialTrigger::Weather->value)
            ->where('created_at', '>=', Carbon::now()->subDays($eventDays))->exists();
        if ($already) {
            $out['skipped'] = 'already shared for this rain event';

            return $out;
        }

        $pool = $this->pool->pool($site);
        if ($pool->isEmpty()) {
            $out['skipped'] = 'rain pool is empty';

            return $out;
        }
        $channels = SocialChannel::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('enabled', true)->with('location')->get();
        $cooldown = (int) ($cfg['cooldown_days'] ?? 30);
        $snapshotBase = ['inches_1d' => $out['inches_1d'], 'inches_2d' => $out['inches_2d'], 'date' => $latest, 'stations' => array_values($this->rain->stations($site))];

        // Each GBP location draws its own post.
        foreach ($channels->filter(fn (SocialChannel $c): bool => $c->network->perLocation() && $c->location_id !== null) as $gbp) {
            $drawn = $this->pool->draw($pool, [(string) $gbp->id], $cooldown);
            if ($drawn === null) {
                continue;
            }
            $lead = $this->lead($site, (string) $gbp->location?->name, $cfg);
            $out['shares'][] = ['post' => (string) $drawn['post']->title, 'networks' => ['gbp'], 'location' => $gbp->location?->name, 'lead' => $lead];
            if ($execute) {
                $snapshot = [...$snapshotBase, 'lead' => $lead, 'days_since_last_share' => $drawn['days_since'], 'location' => $gbp->location?->name];
                $suggestion = $this->board->recordAuto($drawn['post'], SocialTrigger::Weather, 'Heavy rain: '.$this->inches($out), $snapshot, [SocialNetwork::Gbp], [(string) $gbp->location_id]);
                $this->sharer->sharePost($drawn['post'], SocialTrigger::Weather, $snapshot, [SocialNetwork::Gbp], [(string) $gbp->location_id], $suggestion, $lead);
            }
        }

        // The Pages get one draw.
        $pages = $channels->filter(fn (SocialChannel $c): bool => ! $c->network->perLocation());
        if ($pages->isNotEmpty()) {
            $drawn = $this->pool->draw($pool, $pages->pluck('id')->map(fn ($id): string => (string) $id)->all(), $cooldown);
            if ($drawn !== null) {
                $networks = $pages->map(fn (SocialChannel $c): SocialNetwork => $c->network)->unique()->values()->all();
                $lead = $this->lead($site, '', $cfg);
                $out['shares'][] = ['post' => (string) $drawn['post']->title, 'networks' => array_map(fn (SocialNetwork $n): string => $n->value, $networks), 'location' => null, 'lead' => $lead];
                if ($execute) {
                    $snapshot = [...$snapshotBase, 'lead' => $lead, 'days_since_last_share' => $drawn['days_since']];
                    $suggestion = $this->board->recordAuto($drawn['post'], SocialTrigger::Weather, 'Heavy rain: '.$this->inches($out), $snapshot, $networks, null);
                    $this->sharer->sharePost($drawn['post'], SocialTrigger::Weather, $snapshot, $networks, null, $suggestion, $lead);
                }
            }
        }
        if ($out['shares'] === []) {
            $out['skipped'] = 'pool exhausted inside the cooldown';
        }

        return $out;
    }

    /** A storm line not used on the site's last weather share. @param array<string, mixed> $cfg */
    private function lead(Site $site, string $place, array $cfg): string
    {
        $leads = array_values(array_filter((array) ($cfg['leads'] ?? []), 'is_string'));
        if ($leads === []) {
            return '';
        }
        $lastLead = SocialSuggestion::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('trigger', SocialTrigger::Weather->value)->where('status', SocialSuggestionStatus::Auto->value)
            ->orderByDesc('created_at')->value('snapshot');
        $lastLead = is_array($lastLead) ? ($lastLead['lead'] ?? null) : (is_string($lastLead) ? (json_decode($lastLead, true)['lead'] ?? null) : null);
        $choices = array_values(array_filter($leads, fn (string $l): bool => $this->fill($l, $place, $site) !== $lastLead));
        if ($choices === []) {
            $choices = $leads;
        }

        return $this->fill($choices[random_int(0, count($choices) - 1)], $place, $site);
    }

    private function fill(string $template, string $place, Site $site): string
    {
        $area = $place !== '' ? $place : trim((string) ($site->brand_name ?? 'our')).' service area';

        return trim(str_replace(['{place}', '{area}'], [$place !== '' ? $place : 'our area', $area], $template));
    }

    /** @param  array{inches_1d: float, inches_2d: float}  $out */
    private function inches(array $out): string
    {
        return sprintf('%.1f in. in 24h, %.1f in. in 48h', $out['inches_1d'], $out['inches_2d']);
    }
}
