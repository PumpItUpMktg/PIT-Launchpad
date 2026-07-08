<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Publishing\SitePreviewService;
use Illuminate\Console\Command;

/**
 * Preview an ENTIRE site with every section visible — the internal-only "show all sections" pass.
 *
 * Preview-pushes each page to WordPress as a DRAFT (every data-gated section — reviews, guarantee,
 * certifications, credibility, … — renders as a labeled example placeholder), so an operator can
 * review the complete design on the real instance. It is internal-only: drafts are visible to
 * logged-in operators via the preview link, never to visitors; nothing goes live and no
 * `Content.status` flips. Reuses the per-page proof-preview path.
 */
class PreviewSiteCommand extends Command
{
    protected $signature = 'launchpad:preview-site {site : Site id (ULID) or exact brand_name}';

    protected $description = 'Preview every page of a site as WordPress DRAFTS with ALL sections shown (internal-only; nothing goes live).';

    public function handle(SitePreviewService $service): int
    {
        $site = Site::query()->find((string) $this->argument('site'))
            ?? Site::query()->where('brand_name', (string) $this->argument('site'))->first();

        if ($site === null) {
            $this->error("Site not found: {$this->argument('site')} (pass a Site id or exact brand_name).");

            return self::FAILURE;
        }

        $this->info("Previewing '{$site->brand_name}' ({$site->id}) — pushing every page as a WordPress DRAFT with all sections shown. Nothing goes live.");
        $this->newLine();

        $results = $service->previewSite($site);
        if ($results === []) {
            $this->warn('No pages to preview yet — generate the site first.');

            return self::SUCCESS;
        }

        $ready = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($results as $row) {
            $result = $row['result'];
            if ($result->isReady()) {
                $ready++;
                $this->line("  ✓ {$row['slug']} → ".($result->previewUrl ?? '(preview pushed; no URL returned)'));
            } elseif ($result->state === 'unavailable') {
                $skipped++;
                $this->line("  - {$row['slug']} — skipped: {$result->message}");
            } else {
                $failed++;
                $this->line("  ✗ {$row['slug']} — failed: {$result->message}");
            }
        }

        $this->newLine();
        $this->info("Ready {$ready}, skipped {$skipped}, failed {$failed}. These are WordPress DRAFTS — visible to logged-in operators via the preview links, never to visitors.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
