<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Operator\Coverage\LegacyTwinConsolidator;
use Illuminate\Console\Command;

/**
 * Consolidate the legacy numbered twins: 301 every loser → its group's earner, verify serving, then
 * retire the loser post. Report-only by default; --execute applies, group by group.
 */
class ConsolidateLegacyTwinsCommand extends Command
{
    protected $signature = 'launchpad:consolidate-legacy-twins
        {--site= : Site id or brand name}
        {--days=28 : The recent window the earner is judged on (lifetime is the fallback)}
        {--keep=* : Pin a keeper by path (per group; repeatable) — settles an ambiguous group}
        {--limit=0 : Apply to this many groups, biggest first (0 = all)}
        {--execute : Apply (default: report-only — writes nothing)}';

    protected $description = 'Consolidate legacy numbered twins: 301 the copies → the earner, verify serving, then retire them (report-only by default; --execute to apply).';

    public function handle(LegacyTwinConsolidator $consolidator): int
    {
        $site = $this->resolveSite();
        if ($site === null) {
            $this->error('No site found — pass --site= with a site id or brand name.');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        /** @var list<string> $keep */
        $keep = array_values(array_filter(array_map('strval', (array) $this->option('keep'))));
        $limit = max(0, (int) $this->option('limit'));
        $execute = (bool) $this->option('execute');

        $this->info($execute
            ? 'EXECUTE · consolidating legacy twins (write redirect → push → verify → retire).'
            : 'Read-only · legacy-twin consolidation PLAN. Nothing is changed (pass --execute to apply).');

        // The plan asks the site what each keeper answers today — the series is history, and a family
        // whose every copy is already a 404 has nothing to keep.
        $plan = $consolidator->plan($site, $days, $keep, $limit);
        if ($plan['groups'] === []) {
            $this->info('No legacy numbered twins found.');

            return self::SUCCESS;
        }

        $redirects = 0;
        $blocked = 0;
        $dead = 0;
        $member = fn (array $m): string => sprintf('impr %s (%s in %dd) · pos %s · last seen %s', number_format($m['impressions']), number_format($m['window_impressions']), $plan['window_days'], $m['position'] !== null ? (string) $m['position'] : '—', $m['last_seen'] ?? '—');
        foreach ($plan['groups'] as $g) {
            if (! $g['resolvable']) {
                $blocked++;
                $isDead = str_starts_with((string) $g['reason'], 'keeper-dead');
                $dead += $isDead ? 1 : 0;
                $hint = $isDead ? 'nothing live to keep — a family of dead URLs is for the revival flow (launchpad:revive-legacy-content), not a consolidation'
                    : (str_starts_with((string) $g['reason'], 'keeper-') ? 'the keeper is not a live page — pin another with --keep=<path> if one is' : 'decide by hand, then pass --keep=<path>');
                $this->line("  · <fg=yellow>BLOCKED</> ({$g['reason']}) <comment>{$g['base']}</comment> — {$hint}:");
                foreach ($g['members'] as $m) {
                    $this->line('      '.$m['path'].' · '.$member($m));
                }

                continue;
            }
            $tag = $g['reason'] === 'operator-override' ? '<fg=cyan>[--keep]</>' : "[{$g['reason']}]";
            $this->line("  · <comment>{$g['base']}</comment> — keep {$tag} <info>{$g['keeper']['path']}</info> (answers HTTP {$g['keeper_status']} · ".$member($g['keeper']).'):');
            foreach ($g['losers'] as $l) {
                $redirects++;
                $this->line(sprintf('      301 %s → %s (%s), then retire the post.', $l['from'], $l['to'], $member($l)));
            }
        }

        $this->newLine();
        if ($dead > 0) {
            $this->warn("{$dead} group(s) have no live keeper — every copy already answers 404. Google still shows them, so the equity is real but fading: revive the family (launchpad:revive-legacy-content --site=…) and its old URLs 301 to the new post on publish.");
        }
        if ($blocked - $dead > 0) {
            $this->warn(($blocked - $dead).' group(s) left for a human (ambiguous earner or a keeper that is not a live page) — pin one with --keep=<path>.');
        }
        if (! $execute) {
            $this->info("{$redirects} redirect(s) would be written + the copies retired. Re-run with --execute to apply (nothing was changed).");

            return self::SUCCESS;
        }

        $base = rtrim((string) $site->domain_url, '/');
        $purge = [];
        $done = 0;
        foreach ($consolidator->apply($site, $days, $keep, $limit) as $r) {
            $icon = $r['removed'] ? '<fg=green>✓</>' : ($r['verified'] ? '<fg=yellow>~</>' : '<fg=red>✗</>');
            $this->line("      {$icon} {$r['from']} → {$r['to']} — {$r['note']}");
            if ($r['verified']) {
                $done++;
                $purge[] = $base.'/'.trim($r['from'], '/').'/';
            }
        }

        $this->newLine();
        $this->line("{$done} redirect(s) verified serving at origin.");
        if ($purge !== []) {
            $this->warn('If a CDN (e.g. Cloudflare) fronts the site, PURGE these '.count($purge).' URL(s) — the edge may serve the old page until then:');
            foreach ($purge as $url) {
                $this->line("    • {$url}");
            }
        }

        return self::SUCCESS;
    }

    private function resolveSite(): ?Site
    {
        $id = $this->option('site');
        if (! is_string($id) || $id === '') {
            return null;
        }

        return Site::query()->find($id) ?? Site::query()->where('brand_name', $id)->first();
    }
}
