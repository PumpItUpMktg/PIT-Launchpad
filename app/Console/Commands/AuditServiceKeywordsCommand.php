<?php

namespace App\Console\Commands;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Seo\KeywordUsageAuditor;
use Illuminate\Console\Command;

/**
 * Audits every service/hub page's TARGET KEYWORD against how it's actually used on the page — the
 * on-page half of ranking for it. For each page it reports where the keyword lands (slug, SEO title,
 * H1, meta description, body) and a verdict, so an operator can spot pages whose copy or SEO title
 * drifted off their target, or pages with no target set at all. READ-ONLY — never edits or republishes.
 */
class AuditServiceKeywordsCommand extends Command
{
    protected $signature = 'launchpad:audit-service-keywords {--site= : Site id or brand name (required)} {--all : include non-published pages}';

    protected $description = 'Audit each service/hub page: is its target keyword actually used in the slug, SEO title, H1, meta and body? Read-only.';

    public function handle(KeywordUsageAuditor $auditor): int
    {
        $site = $this->resolveSite();
        if ($site === null) {
            $this->error('Site not found — pass --site=<id|brand name>.');

            return self::FAILURE;
        }

        $query = Content::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->whereIn('page_type', [PageType::Service->value, PageType::Hub->value])
            ->whereNotNull('slug')
            ->with('targetKeyword')
            ->orderBy('slug');

        if (! $this->option('all')) {
            $query->where('status', ContentStatus::Published->value);
        }

        $pages = $query->get();
        if ($pages->isEmpty()) {
            $this->info("No service/hub pages for {$site->brand_name}".($this->option('all') ? '' : ' (published)').'.');

            return self::SUCCESS;
        }

        $icons = ['optimized' => '<info>✓ optimized</info>', 'partial' => '<comment>~ partial  </comment>', 'off_target' => '<fg=red>✗ off-target</>', 'no_target' => '<fg=red>✗ no target </>'];
        $counts = ['optimized' => 0, 'partial' => 0, 'off_target' => 0, 'no_target' => 0];

        $this->line("Target-keyword audit for <info>{$site->brand_name}</info> — {$pages->count()} service/hub page(s):");
        $this->newLine();

        foreach ($pages as $page) {
            $r = $auditor->analyze($page);
            $counts[$r['verdict']]++;

            $kw = $r['keyword'] !== null ? "\"{$r['keyword']}\"" : '<fg=red>— none —</>';
            $this->line("{$icons[$r['verdict']]}  /{$r['slug']}  → target {$kw}");

            if ($r['keyword'] !== null) {
                $cells = [];
                foreach ($r['placements'] as $field => $placement) {
                    $mark = match ($placement) {
                        KeywordUsageAuditor::EXACT => "<info>{$field}</info>",
                        KeywordUsageAuditor::PARTIAL => "<comment>{$field}~</comment>",
                        default => "<fg=red>{$field}✗</>",
                    };
                    $cells[] = $mark;
                }
                $this->line('      '.implode('  ', $cells));
            }
        }

        $this->newLine();
        $this->line(sprintf(
            'Summary: <info>%d optimized</info>, <comment>%d partial</comment>, <fg=red>%d off-target</>, <fg=red>%d without a target keyword</>.',
            $counts['optimized'], $counts['partial'], $counts['off_target'], $counts['no_target'],
        ));
        if ($counts['off_target'] > 0 || $counts['no_target'] > 0) {
            $this->warn('Off-target / no-target pages need the target keyword worked into the SEO title + H1 (regenerate or edit in Review).');
        }

        return self::SUCCESS;
    }

    private function resolveSite(): ?Site
    {
        $arg = $this->option('site');

        return is_string($arg) && $arg !== ''
            ? Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first()
            : null;
    }
}
