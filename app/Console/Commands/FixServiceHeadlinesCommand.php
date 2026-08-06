<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Publishing\Seo\HeadlineKeywordFixer;
use Illuminate\Console\Command;

/**
 * The on-demand "now-fixer" for service/hub pages already live with an off-target H1 (per
 * {@see AuditServiceKeywordsCommand}): reworks ONLY the H1 + SEO title + meta
 * description to lead with the page's target keyword, then re-publishes — no body re-draft, no image
 * re-render, and (idempotent by ULID) the WP post + its publish date are preserved. Dry-run by default;
 * --apply writes + re-publishes.
 */
class FixServiceHeadlinesCommand extends Command
{
    protected $signature = 'launchpad:fix-service-headlines {--site= : Site id or brand name (required)} {--apply : write the rewrites and re-publish (default is a dry run)}';

    protected $description = 'Rework off-target service/hub H1 + SEO title + meta to lead with the target keyword, then re-publish (date preserved). Dry-run unless --apply.';

    public function handle(HeadlineKeywordFixer $fixer): int
    {
        $site = $this->resolveSite();
        if ($site === null) {
            $this->error('Site not found — pass --site=<id|brand name>.');

            return self::FAILURE;
        }

        $pages = $fixer->offTargetPages($site);
        if ($pages->isEmpty()) {
            $this->info("No off-target service/hub headlines for {$site->brand_name} — every page's H1 already carries its target keyword.");

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $this->line(($apply ? 'Fixing' : 'DRY RUN — would fix')." {$pages->count()} off-target page(s) for <info>{$site->brand_name}</info>:");
        $this->newLine();

        $applied = 0;
        foreach ($pages as $page) {
            $fix = $fixer->propose($page);
            if ($fix === null || ! $fix->changed()) {
                continue;
            }

            $this->line("<info>/{$page->slug}</info>  → target \"{$fix->keyword}\"");
            $this->line("    H1:    <fg=red>{$fix->oldH1}</>  ⇒  <info>{$fix->newH1}</info>");
            if ($fix->newTitle !== $fix->oldTitle) {
                $this->line("    title: {$fix->oldTitle}  ⇒  <info>{$fix->newTitle}</info>");
            }
            if ($fix->newMeta !== $fix->oldMeta) {
                $this->line("    meta:  <info>{$fix->newMeta}</info>");
            }

            if ($apply) {
                $fixer->apply($fix, null);
                $applied++;
            }
            $this->newLine();
        }

        if ($apply) {
            $this->info("Applied {$applied} fix(es) and queued re-publish (publish dates preserved). Watch the queue drain, then re-run the audit to confirm.");
        } else {
            $this->warn('Dry run — nothing written. Re-run with --apply to rewrite + re-publish.');
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
