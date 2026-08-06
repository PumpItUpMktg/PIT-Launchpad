<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Models\Site;
use App\Publishing\OrphanPagePruner;
use Illuminate\Console\Command;

/**
 * Sweeps NEVER-PUBLISHED duplicate service/hub pages out of the DB — the ghost rows repeated
 * build/materialize runs leave behind (they never reached WordPress). Dry-run by default; --apply
 * soft-deletes them (recoverable). See {@see OrphanPagePruner} for the safety rules — a live/pushed row is
 * never touched, and a URL with more than one live page is flagged for manual review, never auto-pruned.
 */
class PruneOrphanPagesCommand extends Command
{
    protected $signature = 'launchpad:prune-orphan-pages {--site= : Site id or brand name (required)} {--apply : soft-delete the orphan rows (default is a dry run)}';

    protected $description = 'Soft-delete never-published duplicate service/hub pages (DB ghosts from repeated builds). Dry-run unless --apply.';

    public function handle(OrphanPagePruner $pruner): int
    {
        $site = $this->resolveSite();
        if ($site === null) {
            $this->error('Site not found — pass --site=<id|brand name>.');

            return self::FAILURE;
        }

        $plan = $pruner->plan($site);
        $apply = (bool) $this->option('apply');

        if ($plan['flagged'] !== []) {
            $this->warn('Flagged — more than one LIVE page shares a URL leaf (manual review; NOT pruned):');
            foreach ($plan['flagged'] as $f) {
                $this->line("  · <comment>{$f['leaf']}</comment> → ".implode(', ', $f['urls']));
            }
            $this->newLine();
        }

        if ($plan['prune'] === []) {
            $this->info("No never-published duplicate service/hub pages for {$site->brand_name}.");

            return self::SUCCESS;
        }

        $this->line(($apply ? 'Pruning' : 'DRY RUN — would prune').' '.count($plan['prune'])." orphan page(s) for <info>{$site->brand_name}</info> (each has a live canonical):");
        $this->newLine();
        foreach ($plan['prune'] as $item) {
            /** @var Content $row */
            $row = $item['row'];
            /** @var Content $canon */
            $canon = $item['canonical'];
            $this->line("  <fg=red>✗ /{$row->slug}</>  <fg=gray>({$row->status->value})</>  → keep <info>/{$canon->slug}</info> (live)");
        }
        $this->newLine();

        if ($apply) {
            $removed = $pruner->apply($plan['prune']);
            $this->info("Soft-deleted {$removed} orphan page(s). Recoverable via restore if needed.");
        } else {
            $this->warn('Dry run — nothing deleted. Re-run with --apply to soft-delete.');
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
