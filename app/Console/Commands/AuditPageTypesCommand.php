<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Operate\PageTypeAudit;
use App\Support\SiteFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Review a site's pages for page_type misclassification — e.g. a service page showing under "Core Pages"
 * in the console because its page_type is Utility. Read-only by default (lists flagged pages with the
 * evidence); --fix re-points the flagged pages to their true type (Hub for a silo pillar, else Service).
 */
class AuditPageTypesCommand extends Command
{
    protected $signature = 'launchpad:audit-page-types {site : Site id, brand name, or domain (partial ok)}
        {--fix : Re-point the flagged pages to their true type (Service/Hub) — otherwise read-only}';

    protected $description = 'Audit (and optionally repair) page_type misclassification — e.g. service pages stuck in Core.';

    public function handle(PageTypeAudit $audit): int
    {
        $needle = (string) $this->argument('site');
        $matches = SiteFinder::matches($needle);

        if ($matches->isEmpty()) {
            $this->error("No site matches [{$needle}]. Available sites:");
            $this->listSites(SiteFinder::all());

            return self::FAILURE;
        }

        if ($matches->count() > 1) {
            $this->error("[{$needle}] is ambiguous — it matches {$matches->count()} sites. Re-run with the id or exact name:");
            $this->listSites($matches);

            return self::FAILURE;
        }

        $site = $matches->first();
        $result = $audit->audit($site);

        $flagged = array_values(array_filter($result['rows'], fn (array $r): bool => in_array($r['flag'], ['misfiled_core', 'invisible'], true)));

        if ($flagged === []) {
            $this->info("{$site->brand_name} — no page_type problems found across {$this->pageCount($result)} page(s).");

            return self::SUCCESS;
        }

        $this->table(
            ['Title', 'Status', 'page_type', 'standard_type', 'silo', 'svc', 'kw', 'Flag', 'Suggested'],
            array_map(fn (array $r): array => [
                $r['title'], $r['status'], $r['page_type'] ?? '—', $r['standard_type'] ?? '—',
                $r['has_silo'] ? 'Y' : '-', $r['has_service'] ? 'Y' : '-', $r['has_keyword'] ? 'Y' : '-',
                $r['flag'], $r['suggested'] ?? '—',
            ], $flagged),
        );

        $this->line(sprintf('%d misfiled in Core, %d invisible (null page_type).', $result['flagged'], $result['invisible']));

        if (! $this->option('fix')) {
            $this->comment('Read-only. Re-run with --fix to re-point the misfiled pages to Service/Hub.');

            return self::SUCCESS;
        }

        $repair = $audit->repair($site);
        foreach ($repair['details'] as $d) {
            $this->line("  fixed: {$d['title']} — {$d['from']} → {$d['to']}");
        }
        $this->info("{$site->brand_name} — re-pointed {$repair['fixed']} misfiled page(s). (Invisible/null-type rows are left for manual review.)");

        return self::SUCCESS;
    }

    /** @param  array{rows: list<array<string, mixed>>, flagged: int, invisible: int}  $result */
    private function pageCount(array $result): int
    {
        return count($result['rows']);
    }

    /** @param  Collection<int, Site>  $sites */
    private function listSites(Collection $sites): void
    {
        if ($sites->isEmpty()) {
            $this->line('  (none)');

            return;
        }

        $this->table(
            ['Brand name', 'Site id', 'Domain'],
            $sites->map(fn (Site $s): array => [$s->brand_name, $s->id, $s->domain_url])->all(),
        );
    }
}
