<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Operator\Coverage\LegacyTwins;
use Illuminate\Console\Command;

/**
 * The legacy numbered twins (`/foo/`, `/foo-2/`, `/foo-8/` — none of them ours) grouped by the title
 * they copy, the earner named per group, and the redirects a consolidation would write. Report only.
 */
class ReportLegacyTwinsCommand extends Command
{
    protected $signature = 'launchpad:report-legacy-twins
        {--site= : Site id or brand name}
        {--days=28 : The recent window the earner is judged on (lifetime is the fallback)}
        {--limit=0 : Show this many groups, biggest first (0 = all)}';

    protected $description = 'Group the legacy numbered twins by the title they copy, name the earner per group, and show what a consolidation would redirect (report only).';

    public function handle(LegacyTwins $twins): int
    {
        $site = $this->resolveSite();
        if ($site === null) {
            $this->error('No site found — pass --site= with a site id or brand name.');

            return self::FAILURE;
        }

        $r = $twins->for($site, max(1, (int) $this->option('days')));
        $t = $r['totals'];

        $this->info($site->brand_name.' — legacy numbered twins (none of these are pages Launchpad published)');
        $this->newLine();

        if ($r['groups'] === []) {
            $this->line('<info>None.</info> No URL Google has shown is a numbered twin of a legacy page.');

            return self::SUCCESS;
        }

        $this->line(sprintf('<info>%d</info> group(s) · <info>%d</info> numbered twin(s) · %s impression(s) lifetime, %s in the last %d days.',
            $t['groups'], $t['twins'], number_format($t['impressions']), number_format($t['window_impressions']), $r['window_days']));
        $this->line(sprintf('%d resolvable (the rule names an earner → %d redirect(s)) · %d ambiguous (a human chooses).',
            $t['resolvable'], $t['redirects'], $t['ambiguous']));
        $this->newLine();

        $limit = (int) $this->option('limit');
        $groups = $limit > 0 ? array_slice($r['groups'], 0, $limit) : $r['groups'];

        $rows = [];
        foreach ($groups as $g) {
            $first = true;
            foreach ($g['members'] as $m) {
                $role = $g['keeper'] !== null && $m['path'] === $g['keeper']['path'] ? 'KEEP' : ($g['keeper'] !== null ? '→ '.$g['keeper']['path'] : '?');
                $rows[] = [
                    $first ? $g['base'] : '',
                    $first ? $g['reason'] : '',
                    $m['path'],
                    number_format($m['impressions']),
                    number_format($m['window_impressions']),
                    number_format($m['clicks']),
                    $m['position'] !== null ? number_format($m['position'], 1) : '—',
                    $m['last_seen'] ?? '—',
                    $role,
                ];
                $first = false;
            }
        }
        $this->table(['Group', 'Rule', 'URL', 'Impr. lifetime', 'Impr. '.$r['window_days'].'d', 'Clicks', 'Pos.', 'Last seen', 'Consolidation'], $rows);

        $this->newLine();
        $this->line('KEEP = the earner (most impressions in the window; lifetime when the window is quiet). Every other member');
        $this->line('would 301 to it, verified serving before anything is removed — that is the consolidation, not this report.');
        $this->line('"ambiguous-earner" = a tie at the top: the operator names the keeper. "Last seen" is the newest day a URL');
        $this->line('earned an impression; a stale date on a KEEP row is worth a look before trusting it.');
        if ($limit > 0 && count($r['groups']) > $limit) {
            $this->line(sprintf('Showing %d of %d groups — raise --limit to see the rest.', $limit, count($r['groups'])));
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
