<?php

namespace App\Console\Commands;

use App\Enums\LinkFindingType;
use App\Models\Site;
use App\Publishing\Links\InternalLinkAuditor;
use App\Publishing\Links\LinkFinding;
use Illuminate\Console\Command;

/**
 * Read-only internal-link doctor: scans a site's PUBLISHED pages and reports where the internal linking
 * is thin — pages with no inbound link (orphans), pages that link nowhere (dead ends), and copy that
 * names another page's ranking term without linking it (a link to add). Reconstructs the link graph from
 * the control plane (no live WordPress calls); it does not change anything — corrections are a separate,
 * reviewed step.
 */
class AuditLinksCommand extends Command
{
    protected $signature = 'launchpad:audit-links {site : Site id or brand name}';

    protected $description = 'Scan a site\'s published pages for internal-link gaps (orphans, dead ends, link opportunities).';

    public function handle(InternalLinkAuditor $auditor): int
    {
        $site = Site::withoutGlobalScopes()
            ->where('id', $this->argument('site'))
            ->orWhere('brand_name', $this->argument('site'))
            ->first();

        if ($site === null) {
            $this->error("No site matches [{$this->argument('site')}].");

            return self::FAILURE;
        }

        $findings = $auditor->audit($site);

        $this->line("<info>{$site->brand_name}</info> ({$site->id}) — internal-link audit of published pages");

        if ($findings === []) {
            $this->info('  Clean — every published page has inbound + outbound links and no unlinked mentions.');

            return self::SUCCESS;
        }

        foreach ([LinkFindingType::Orphan, LinkFindingType::DeadEnd, LinkFindingType::Opportunity] as $type) {
            $group = array_values(array_filter($findings, fn (LinkFinding $f): bool => $f->type === $type));
            if ($group === []) {
                continue;
            }

            $this->newLine();
            $this->line('<comment>'.$type->label().'</comment> ('.count($group).')');
            foreach ($group as $finding) {
                $suffix = $finding->suggestedLabel !== null
                    ? ($type === LinkFindingType::Opportunity ? '  → link to “'.$finding->suggestedLabel.'”' : '  → via “'.$finding->suggestedLabel.'”')
                    : '';
                $this->line('  • '.$finding->url.'  ('.$finding->title.')'.$suffix);
                if ($type === LinkFindingType::Opportunity) {
                    $this->line('      '.$finding->detail);
                }
            }
        }

        $this->newLine();
        $this->line('Read-only — nothing changed. A corrective pass (add the links + re-publish) is the reviewed next step.');

        return self::SUCCESS;
    }
}
