<?php

namespace App\Social\Rules;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\SocialTrigger;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Social\SuggestionBoard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Upward movement in Search Console → a re-share suggestion. Any one fires: trailing-7-day impressions at
 * least `impressions_ratio`× the prior 7 days with `impressions_min` or more; average position improved
 * by `position_gain` or more and now inside `position_within`; or first clicks — `clicks_min` in the
 * window after none before. The suggestion carries the numbers, so the card reads "impressions 14 → 41,
 * position 18 → 9". Search Console lags about two days; the windows end at the latest synced day.
 */
final class MomentumRule
{
    public function __construct(private readonly SuggestionBoard $board) {}

    /** @return list<array{content_id: string, title: string, reason: string, snapshot: array<string, mixed>, suggested: bool}> */
    public function run(Site $site, bool $execute = true): array
    {
        $domain = rtrim((string) $site->domain_url, '/');
        if ($domain === '') {
            return [];
        }
        $cfg = (array) config('launchpad.social.momentum', []);
        $latest = DB::table('gsc_url_daily')->where('site_id', $site->id)->max('date');
        if ($latest === null) {
            return [];
        }
        $end = Carbon::parse((string) $latest);
        $recentFrom = $end->copy()->subDays(6);
        $priorFrom = $end->copy()->subDays(13);
        $priorTo = $end->copy()->subDays(7);

        $posts = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('kind', ContentKind::Post->value)->where('status', ContentStatus::Published->value)
            ->whereNotNull('slug')->with('site')->get();
        $out = [];
        foreach ($posts as $post) {
            $path = '/'.trim((string) $post->slug, '/').'/';
            $recent = $this->window($site, $domain, $path, $recentFrom, $end);
            $prior = $this->window($site, $domain, $path, $priorFrom, $priorTo);
            $fired = $this->fired($recent, $prior, $cfg);
            if ($fired === null) {
                continue;
            }
            $snapshot = [
                'impressions' => [$prior['impressions'], $recent['impressions']],
                'clicks' => [$prior['clicks'], $recent['clicks']],
                'position' => [$prior['position'], $recent['position']],
                'window' => [$recentFrom->toDateString(), $end->toDateString()],
                'rule' => $fired,
            ];
            $reason = $this->reason($fired, $recent, $prior);
            $suggested = false;
            if ($execute) {
                $suggested = $this->board->suggest($post, SocialTrigger::Momentum, $reason, $snapshot) !== null;
            }
            $out[] = ['content_id' => (string) $post->id, 'title' => (string) $post->title, 'reason' => $reason, 'snapshot' => $snapshot, 'suggested' => $suggested];
        }

        return $out;
    }

    /** @return array{impressions: int, clicks: int, position: float|null} */
    private function window(Site $site, string $domain, string $path, Carbon $from, Carbon $to): array
    {
        $row = DB::table('gsc_url_daily')
            ->where('site_id', $site->id)
            ->whereRaw('date(date) BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])   // the column is a date on the sync's rows and a datetime on model-saved ones
            ->where(fn ($q) => $q->where('url', $domain.$path)->orWhere('url', rtrim($domain.$path, '/')))
            ->selectRaw('COALESCE(SUM(impressions),0) AS imp, COALESCE(SUM(clicks),0) AS clk, SUM(position * impressions) AS pw, SUM(impressions) AS pwd')
            ->first();
        $imp = (int) ($row->imp ?? 0);
        $pwd = (float) ($row->pwd ?? 0);

        return [
            'impressions' => $imp,
            'clicks' => (int) ($row->clk ?? 0),
            'position' => $pwd > 0 ? round((float) $row->pw / $pwd, 1) : null,
        ];
    }

    /**
     * @param  array{impressions: int, clicks: int, position: float|null}  $recent
     * @param  array{impressions: int, clicks: int, position: float|null}  $prior
     * @param  array<string, mixed>  $cfg
     */
    private function fired(array $recent, array $prior, array $cfg): ?string
    {
        $ratio = (float) ($cfg['impressions_ratio'] ?? 2.0);
        $min = (int) ($cfg['impressions_min'] ?? 30);
        if ($recent['impressions'] >= $min && $recent['impressions'] >= $ratio * max(1, $prior['impressions'])) {
            return 'impressions';
        }
        $gain = (float) ($cfg['position_gain'] ?? 3.0);
        $within = (float) ($cfg['position_within'] ?? 20.0);
        if ($recent['position'] !== null && $prior['position'] !== null && $recent['position'] <= $within && $gain <= $prior['position'] - $recent['position']) {
            return 'position';
        }
        if ($prior['clicks'] === 0 && $recent['clicks'] >= (int) ($cfg['clicks_min'] ?? 3)) {
            return 'first_clicks';
        }

        return null;
    }

    /**
     * @param  array{impressions: int, clicks: int, position: float|null}  $recent
     * @param  array{impressions: int, clicks: int, position: float|null}  $prior
     */
    private function reason(string $rule, array $recent, array $prior): string
    {
        return match ($rule) {
            'impressions' => sprintf('Impressions %d → %d this week', $prior['impressions'], $recent['impressions']),
            'position' => sprintf('Position %s → %s', $prior['position'] ?? '—', $recent['position'] ?? '—'),
            default => sprintf('First clicks: %d this week', $recent['clicks']),
        };
    }
}
