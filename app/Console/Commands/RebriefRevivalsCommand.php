<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Redirects\RevivalBrief;
use Illuminate\Console\Command;

/**
 * Re-brief revival candidates that were created with a single-query brief.
 *
 *   launchpad:rebrief-revivals --site=... [--apply]
 *
 * A revived post replaces its family and 301s the originals onto it, so the brief decides how much of the
 * cluster's traffic survives. Candidates created before {@see RevivalBrief} carry only their best query —
 * an eleven-URL family earning 361,049 impressions briefed as one phrase — and generating them in that
 * state spends a Sonnet draft and a render on an article aimed at a fraction of what the pages earn.
 *
 * Only candidates that have NOT been drafted are touched: once a draft exists the brief has already done
 * its work, and rewriting it would change the record of what the drafter was actually asked for.
 *
 * Dry-run by default. Never drafts, never publishes.
 */
class RebriefRevivalsCommand extends Command
{
    protected $signature = 'launchpad:rebrief-revivals
        {--site= : Site id or brand name (required)}
        {--apply : Write the updated briefs}';

    protected $description = 'Re-brief undrafted revival candidates on every query their family earns, not just the best one.';

    public function handle(RevivalBrief $brief): int
    {
        $arg = trim((string) $this->option('site'));
        if ($arg === '') {
            $this->error('--site is required (id or brand name).');

            return self::FAILURE;
        }
        $site = Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first();
        if ($site === null) {
            $this->error("No site matches [{$arg}].");

            return self::FAILURE;
        }

        $candidates = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('meta->revived_from_urls')
            ->get();

        $this->line("<info>{$site->brand_name}</info> — re-brief revival candidates");

        $touched = 0;
        $skipped = 0;
        foreach ($candidates as $candidate) {
            if ($candidate->hasDraft()) {
                $skipped++;

                continue;   // the brief already did its job; the record of what was asked stands
            }

            $meta = is_array($candidate->meta) ? $candidate->meta : [];
            $urls = is_array($meta['revived_from_urls'] ?? null) ? array_values(array_filter(
                $meta['revived_from_urls'], fn ($u): bool => is_string($u) && $u !== '',
            )) : [];
            if ($urls === []) {
                continue;
            }

            $queries = $brief->for($site, $urls);
            if (count($queries) < 2) {
                continue;   // nothing more to say than it already says
            }

            $this->newLine();
            $this->line(sprintf('  %s', (string) $candidate->title));
            $this->line(sprintf('    was: “%s”', (string) ($meta['revived_query'] ?? '—')));
            // The guard above already skipped anything with fewer than two, so this is always plural.
            $this->line(sprintf('    now: %d queries across %d URL(s) — %s',
                count($queries), count($urls),
                implode(', ', array_map(fn (array $q): string => '“'.$q['query'].'”', array_slice($queries, 0, 4)))
                .(count($queries) > 4 ? ', …' : '')));

            $touched++;
            if (! $this->option('apply')) {
                continue;
            }

            $meta['revived_queries'] = $queries;
            $candidate->forceFill([
                'meta' => $meta,
                'angle_hint' => trim((string) $candidate->angle_hint).' '.$brief->sentence($queries),
            ])->save();
        }

        $this->newLine();
        $this->line(sprintf('%d candidate(s) %s, %d already drafted and left alone.',
            $touched, $this->option('apply') ? 're-briefed' : 'would be re-briefed', $skipped));

        if (! $this->option('apply')) {
            $this->comment('Dry run — re-run with --apply to write them.');
        }

        return self::SUCCESS;
    }
}
