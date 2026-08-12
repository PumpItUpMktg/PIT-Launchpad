<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Operator\IndexCoverage;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Audit a tenant's REAL Google index coverage — runs a URL Inspection for every published page + post and
 * reports "X of Y indexed" plus the per-state breakdown (crawled-not-indexed, discovered-not-indexed,
 * excluded-by-redirect, …). This is the authoritative answer to "does our marked index match Google?",
 * distinct from the impressions>0 badge on the cards.
 *
 * Quota-guarded + cached (see {@see \App\Integrations\UrlInspection\GoogleIndexInspector}) — safe to
 * re-run; cached URLs cost no API call, and the run stops inspecting new URLs once the per-day cap is
 * reached (reported as "not inspected"). After a run the Live/blog cards show the real state from cache.
 *
 * Runnable ad hoc for one tenant (`--site=`), or across every GSC-connected site when `--site` is
 * omitted — the form the weekly schedule uses so the index verdict + crawl date on the cards stay fresh
 * without an operator having to remember to run it.
 */
class AuditIndexCommand extends Command
{
    protected $signature = 'launchpad:audit-index {--site= : Site id or brand name (all GSC-connected sites if omitted)}';

    protected $description = 'Audit real Google index coverage per URL (indexed / crawled-not-indexed / excluded-by-redirect) via URL Inspection.';

    public function handle(IndexCoverage $coverage): int
    {
        $sites = $this->resolveSites();
        if ($sites === null) {
            return self::FAILURE;
        }
        if ($sites->isEmpty()) {
            $this->warn('No GSC-connected sites (none has a gsc_property picked) — nothing to audit.');

            return self::SUCCESS;
        }

        foreach ($sites as $site) {
            $this->auditSite($site, $coverage);
        }

        return self::SUCCESS;
    }

    /** Run + report the index-coverage audit for a single tenant. */
    private function auditSite(Site $site, IndexCoverage $coverage): void
    {
        $r = $coverage->audit($site, live: true);

        if (! $r['connected']) {
            $this->warn("{$site->brand_name}: Search Console not connected (no grant or no GSC property picked) — nothing inspected.");

            return;
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
    }

    /**
     * One tenant when `--site` is given (id or brand name), else every GSC-connected site — the same
     * "has a gsc_property picked" rule the inspector uses to decide a tenant is connected.
     *
     * @return Collection<int, Site>|null null on an unresolvable --site
     */
    private function resolveSites(): ?Collection
    {
        $arg = trim((string) $this->option('site'));

        if ($arg !== '') {
            $site = Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first();
            if ($site === null) {
                $this->error("No site matches [{$arg}].");

                return null;
            }

            return collect([$site]);
        }

        return Site::query()
            ->whereNotNull('gsc_property')
            ->where('gsc_property', '!=', '')
            ->get();
    }
}
