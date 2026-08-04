<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Publishing\Schema\LocationSchemaAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Audits the LocalBusiness NAP on every location (storefront) page for a tenant ({@see
 * LocationSchemaAuditor}) — composes the REAL JSON-LD node that ships and flags the five failure modes:
 * missing parentOrganization link, a telephone that collides with the corporate #org line, a storefront
 * with no address, and areaServed leaking onto #org. Prints each location's resolved telephone + address
 * so the address can be compared against the GBP listing by eye (check 4 can't be automated here).
 *
 * Read-only. A site id/brand scopes it to one tenant; omit to sweep every tenant.
 */
class AuditLocationSchemaCommand extends Command
{
    protected $signature = 'launchpad:audit-location-schema {site? : Site id or brand name (omit to sweep every tenant)}';

    protected $description = 'Audit the LocalBusiness NAP schema on location/storefront pages (parentOrganization, telephone collision, storefront address). Read-only.';

    public function handle(LocationSchemaAuditor $auditor): int
    {
        $sites = $this->resolveSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $problems = 0;
        foreach ($sites as $site) {
            $rows = $auditor->audit($site);
            if ($rows === []) {
                continue;
            }

            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id}) — corporate #org line: ".($rows[0]['org_telephone'] ?? '—'));
            foreach ($rows as $row) {
                $tag = $row['storefront'] ? 'storefront' : 'service-area';
                $mark = $row['ok'] ? '<info>✓</info>' : '<comment>⚠</comment>';
                $this->line("  {$mark} {$row['page']} [{$tag}]");
                $this->line('      tel: '.($row['telephone'] ?? '—').'   address: '.($row['address'] ?? '—'));
                foreach ($row['flags'] as $flag) {
                    $this->line("      <comment>→ {$flag}</comment>");
                    $problems++;
                }
            }
        }

        $this->newLine();
        if ($problems === 0) {
            $this->info('Every location page emits a clean LocalBusiness NAP (parentOrganization present, no telephone collision, storefront addresses set).');

            return self::SUCCESS;
        }

        $this->comment("Found {$problems} NAP schema issue(s) above. Most are DATA — populate the Location's address/phone (matching its GBP listing) and repush the page.");

        return self::SUCCESS;
    }

    /** @return Collection<int, Site>|null */
    private function resolveSites(): ?Collection
    {
        $arg = $this->argument('site');
        if ($arg === null) {
            return Site::query()->get();
        }

        $site = Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first();
        if ($site === null) {
            $this->error("No site matches [{$arg}].");

            return null;
        }

        return collect([$site]);
    }
}
