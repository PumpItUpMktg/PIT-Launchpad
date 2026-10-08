<?php

namespace App\Console\Commands;

use App\Enums\RedirectSource;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\PublishRedirectsService;
use App\Publishing\Redirects\RevivalReview;
use Illuminate\Console\Command;

/**
 * The revival plan, reviewed before it is applied: which families revive cleanly, which a live post
 * already covers (redirect instead), which share a brief (fold), which need a human (decide) or a
 * better brief (rebrief). Report only; --redirect-covered --apply writes the one thing it is sure of.
 */
class ReviewRevivalsCommand extends Command
{
    protected $signature = 'launchpad:review-revivals
        {--site= : Site id or brand name}
        {--min-impressions= : Impression floor (default config, 5000)}
        {--limit= : Max families reviewed (default config, 100)}
        {--redirect-covered : 301 the families a live post already covers → that post (preview unless --apply)}
        {--apply : With --redirect-covered: write the redirects and push them to WordPress}';

    protected $description = 'Review the legacy revival plan before applying it: already covered, shared brief, weak brief, not an article, off the footprint.';

    public function handle(RevivalReview $review, PublishRedirectsService $redirects): int
    {
        $site = $this->resolveSite();
        if ($site === null) {
            $this->error('No site found — pass --site= with a site id or brand name.');

            return self::FAILURE;
        }

        $floor = $this->option('min-impressions') !== null ? max(0, (int) $this->option('min-impressions')) : null;
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $r = $review->for($site, $floor, $limit);

        $this->info($site->brand_name.' — revival plan, reviewed');
        $this->newLine();
        if ($r['families'] === []) {
            $this->line('<info>Nothing to review.</info> The revival plan is empty (see launchpad:revive-legacy-content for why).');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($r['families'] as $f) {
            $n = count($f['from_urls']);
            $rows[] = [
                $f['key'].($n > 1 ? " (+{$n} URLs)" : ''),
                number_format($f['impressions']),
                mb_strimwidth((string) ($f['query'] ?? '—'), 0, 40, '…'),
                strtoupper($f['verdict']),
                $f['flags'] === [] ? 'revive as planned' : implode('; ', $f['flags']),
            ];
        }
        $this->table(['Family', 'Impr.', 'Brief', 'Verdict', 'Why'], $rows);

        $c = $r['counts'];
        $this->newLine();
        $this->line(sprintf('<info>%d</info> clean · %d covered by a live post (redirect) · %d share a brief (fold) · %d need a decision · %d need a better brief.',
            $c[RevivalReview::CLEAN] ?? 0, $c[RevivalReview::REDIRECT] ?? 0, $c[RevivalReview::FOLD] ?? 0, $c[RevivalReview::DECIDE] ?? 0, $c[RevivalReview::REBRIEF] ?? 0));
        if (! $r['silo_check']) {
            $this->line('(No silo rule_sets on this site, so the footprint check did not run.)');
        }
        $this->newLine();
        $this->line('CLEAN families apply with:  <comment>launchpad:revive-legacy-content --site='.$site->id.' --clean --limit=15 --apply</comment>');
        $this->line('REBRIEF: rebrief first (launchpad:rebrief-revivals) or accept the query knowingly. FOLD: revive the lead only; its post');
        $this->line('absorbs the folded URLs when the lead is rebriefed on every query its family earns. DECIDE: a human reads the URL.');

        $covered = $review->coveredRedirects($r);
        if (! $this->option('redirect-covered')) {
            if ($covered !== []) {
                $this->line(sprintf('REDIRECT: %d URL(s) want a 301 to the live post that covers them — preview with <comment>--redirect-covered</comment>, write with <comment>--redirect-covered --apply</comment>.', count($covered)));
            }

            return self::SUCCESS;
        }

        $this->newLine();
        if ($covered === []) {
            $this->line('No family is covered by a live post — nothing to redirect.');

            return self::SUCCESS;
        }
        foreach ($covered as $row) {
            $this->line(sprintf('  301 %s → %s  (“%s”)', $row['from'], $row['to'], $row['title']));
        }
        if (! $this->option('apply')) {
            $this->comment('Preview — add --apply to write these redirects and push them to WordPress.');

            return self::SUCCESS;
        }

        foreach ($covered as $row) {
            Redirect::withoutGlobalScope(SiteScope::class)->updateOrCreate(
                ['site_id' => $site->id, 'from_url' => $row['from']],
                ['to_url' => $row['to'], 'code' => 301, 'status' => 'active', 'source' => RedirectSource::Migration->value],
            );
        }
        $redirects->publish($site);
        $this->info(sprintf('Wrote %d redirect(s) and pushed the active set to WordPress. The revival plan drops these URLs on its next run.', count($covered)));

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
