<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Operator\Coverage\SlugCollisions;
use Illuminate\Console\Command;

/**
 * Settle the "duplicate of a published page" twins: for each numbered URL Google has shown whose base is
 * a page we publish, ask WordPress where our content actually lives and what answers at the twin. Report
 * only by default; --execute adopts WordPress's URL for the pages it serves at the numbered one.
 */
class CheckSlugCollisionsCommand extends Command
{
    protected $signature = 'launchpad:check-slug-collisions
        {--site= : Site id or brand name}
        {--no-live : Skip the WordPress diagnose and the per-twin HTTP read (lists the twins only)}
        {--execute : Adopt the URL WordPress serves for every page it serves at a twin (the only write)}';

    protected $description = 'Where does our page really live when Google holds a numbered twin of its URL? Asks WordPress; --execute adopts its answer.';

    public function handle(SlugCollisions $collisions): int
    {
        $site = $this->resolveSite();
        if ($site === null) {
            $this->error('No site found — pass --site= with a site id or brand name.');

            return self::FAILURE;
        }

        $r = $collisions->for($site, live: ! $this->option('no-live'));

        $this->info($site->brand_name.' — numbered twins of pages we publish');
        $this->newLine();

        if ($r['live_error'] !== null) {
            $this->warn('WordPress could not be read for at least one page: '.$r['live_error']);
            $this->newLine();
        }

        if ($r['pages'] === []) {
            $this->line('<info>None.</info> No URL Google has shown is a numbered twin of a page we publish.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($r['pages'] as $p) {
            $first = true;
            foreach ($p['twins'] as $t) {
                $rows[] = [
                    $first ? mb_strimwidth($p['title'], 0, 34, '…') : '',
                    $first ? '/'.$p['slug'].'/' : '',
                    $first ? number_format($p['impressions']) : '',
                    $first ? ($p['verdict'] ?? '—') : '',
                    $first ? ($p['wp_slug'] !== null ? '/'.$p['wp_slug'].'/' : '?') : '',
                    '/'.ltrim((string) parse_url($t['url'], PHP_URL_PATH), '/'),
                    number_format($t['impressions']),
                    $t['verdict'] ?? '—',
                    $t['answer'].($t['location'] !== null ? ' → '.$t['location'] : ''),
                    $first ? $p['state'] : '',
                ];
                $first = false;
            }
        }
        $this->table(['Page', 'Our URL', 'Impr.', 'Google', 'WordPress serves it at', 'Twin', 'Impr.', 'Google', 'Twin answers', 'Verdict'], $rows);

        $this->newLine();
        foreach ($r['pages'] as $p) {
            $this->line('<comment>'.$p['title'].'</comment> — '.$p['action']);
        }

        $ours = $r['counts'][SlugCollisions::OURS_AT_TWIN] ?? 0;
        $this->newLine();
        $this->line(sprintf('%d page(s) WordPress serves at a numbered URL (our tracking reads the wrong page) · %d legacy twin(s) still live · %d already redirecting · %d gone · %d unknown.',
            $ours,
            $r['counts'][SlugCollisions::TWIN_LIVE] ?? 0,
            $r['counts'][SlugCollisions::TWIN_REDIRECTS] ?? 0,
            $r['counts'][SlugCollisions::TWIN_GONE] ?? 0,
            $r['counts'][SlugCollisions::UNKNOWN] ?? 0,
        ));

        if (! $this->option('execute')) {
            if ($ours > 0) {
                $this->line('Dry run — add <comment>--execute</comment> to adopt the URL WordPress serves for those '.$ours.' page(s). The legacy twins are left for the consolidation.');
            }

            return self::SUCCESS;
        }

        $done = $collisions->repoint($site, $r);
        foreach ($done['repointed'] as $row) {
            $this->line(sprintf('<info>Adopted</info> %s: /%s/ → /%s/', $row['title'], $row['from'], $row['to']));
        }
        foreach ($done['skipped'] as $row) {
            $this->warn(sprintf('Skipped %s: %s', $row['title'], $row['reason']));
        }
        $this->line(sprintf('%d page(s) repointed, %d skipped. Re-check indexing so the new URLs get a verdict.', count($done['repointed']), count($done['skipped'])));

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
