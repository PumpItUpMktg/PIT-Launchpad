<?php

namespace App\Console\Commands;

use App\Integrations\UrlInspection\GoogleIndexInspector;
use App\Models\Site;
use App\Operator\IndexCoverage;
use Illuminate\Console\Command;

/**
 * Audit a tenant's REAL Google index coverage — runs a URL Inspection for every published page + post and
 * reports "X of Y indexed" plus the per-state breakdown (crawled-not-indexed, discovered-not-indexed,
 * excluded-by-redirect, …). This is the authoritative answer to "does our marked index match Google?",
 * distinct from the impressions>0 badge on the cards.
 *
 * Quota-guarded + cached (see {@see GoogleIndexInspector}) — safe to re-run;
 * cached URLs cost no API call, and the run stops inspecting new URLs once the per-day cap is reached
 * (reported as "not inspected"). After a run the Live/blog cards show the real state from cache.
 */
class AuditIndexCommand extends Command
{
    protected $signature = 'launchpad:audit-index {--site= : Site id or brand name (required)}';

    protected $description = 'Audit real Google index coverage per URL (indexed / crawled-not-indexed / excluded-by-redirect) via URL Inspection.';

    public function handle(IndexCoverage $coverage): int
    {
        $arg = trim((string) $this->option('site'));
        if ($arg === '') {
            $this->error('--site is required (id or brand name).');

            return self::FAILURE;
        }

        $site = Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first();
        if ($site === null) {
            $this->error("No site matches [{$arg}].");

            return self::FAILURE;
        }

        $r = $coverage->audit($site, live: true);

        if (! $r['connected']) {
            $this->warn("{$site->brand_name}: Search Console not connected (no grant or no GSC property picked) — nothing inspected.");

            return self::SUCCESS;
        }

        $this->line("<info>{$site->brand_name}</info> ({$site->id}) — index coverage");
        $this->line("  <comment>{$r['indexed']}</comment> of {$r['total']} published URLs indexed; {$r['inspected']} inspected, {$r['not_inspected']} not inspected (quota/pending).");

        foreach ($r['by_state'] as $state => $count) {
            $this->line(sprintf('  • %-24s %d', $state, $count));
        }

        // The actionable rows: real "not indexed" states (a redirect exclusion is EXPECTED, so list it
        // separately, not as a problem) + any canonical Google disagrees with.
        $problems = array_filter($r['findings'], fn (array $f): bool => in_array($f['state'], ['crawled_not_indexed', 'discovered_not_indexed', 'not_indexed_other', 'excluded_blocked', 'unknown'], true));
        $mismatch = array_filter($r['findings'], fn (array $f): bool => $f['canonical_mismatch']);

        if ($problems !== []) {
            $this->newLine();
            $this->line('  <comment>Not indexed (worth a look):</comment>');
            foreach ($problems as $f) {
                $this->line("    - {$f['url']}  [{$f['label']}]");
            }
        }
        if ($mismatch !== []) {
            $this->newLine();
            $this->line('  <comment>Google chose a different canonical:</comment>');
            foreach ($mismatch as $f) {
                $this->line("    - {$f['url']}  →  {$f['google_canonical']}");
            }
        }

        return self::SUCCESS;
    }
}
