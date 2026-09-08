<?php

namespace App\Console\Commands;

use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Operate\InternalLinkReport;
use Illuminate\Console\Command;

/**
 * READ-ONLY report of a site's two internal-linking mechanisms against the target link policy — writes
 * nothing, applies nothing, proposes nothing (unlike `launchpad:plan-links`, which persists a plan). It
 * exists to answer "what is the shape of the link set before any get applied?" so the enforcement policy
 * is decided on numbers, not after the fact.
 *
 * Two sets, side by side (see {@see InternalLinkReport}): the AUDIT-opportunity set (the "New link
 * available" findings — relevance-gated, capped 3/page) and the PLAN spine (the five-source proposed
 * inbound links, previewed without persisting). For each: the direction breakdown, reciprocal-pair count
 * (the link-wheel signature), projected outbound-per-source after applying vs a word-scaled cap, and how
 * many targets already rank top 3 (and need no link). All tenants, or one via --site.
 */
class ReportInternalLinksCommand extends Command
{
    protected $signature = 'launchpad:report-internal-links
        {--site= : Limit to one site id or brand name}';

    protected $description = 'Read-only: report the internal-link audit set + plan spine against the link policy (no writes).';

    public function handle(InternalLinkReport $report): int
    {
        $opt = trim((string) $this->option('site'));
        if ($opt !== '') {
            $site = Site::withoutGlobalScope(VisibleSiteScope::class)->where('id', $opt)->orWhere('brand_name', $opt)->first();
            if ($site === null) {
                $this->error("No site matches [{$opt}].");

                return self::FAILURE;
            }
            $sites = collect([$site]);
        } else {
            $sites = Site::query()->get();
        }

        $this->info('Read-only · internal-link shape vs policy: ~1 body link / 150 words (ceiling 10) · inbound 3–5 (none for a top-3 page) · no reciprocals · downward · relevance required.');
        $this->line('Nothing is written, proposed, or applied.');

        foreach ($sites as $site) {
            $data = $report->forSite($site);
            if ($data['audit']['total'] === 0 && $data['plan']['total'] === 0) {
                continue;
            }

            $this->newLine();
            $this->line("<options=bold>=== {$site->brand_name} ({$site->id}) ===</>");

            $this->renderSet('AUDIT set — copy-cited opportunities (relevance-gated, capped 3/page)', $data['audit']);
            $this->line("  context: {$data['audit']['orphans']} orphan(s) · {$data['audit']['dead_ends']} dead-end(s)");

            $this->newLine();
            $this->renderSet('PLAN spine — five-source proposed inbound links (previewAll, not persisted)', $data['plan']);
            if (isset($data['plan']['by_source_type'])) {
                $this->line('  by source type: '.$this->kv($data['plan']['by_source_type']));
            }
        }

        $this->newLine();
        $this->line('Note: "existing outbound" counts every internal link the graph models (structural spine + contextual body); the word-scaled cap targets contextual body links, so a projected total is an UPPER bound. The signal is which sources blow past the cap regardless.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $set */
    private function renderSet(string $title, array $set): void
    {
        $this->line("<options=bold>{$title}</>");
        $this->line("  total {$set['total']} · sources {$set['distinct_sources']} · targets {$set['distinct_targets']}");
        $this->line('  breakdown: '.($this->kv($set['breakdown']) ?: '—'));
        $recTone = $set['reciprocal_pairs'] > 0 ? '<fg=red>' : '<fg=green>';
        $this->line("  reciprocal pairs (A↔B): {$recTone}{$set['reciprocal_pairs']}</>");
        $this->line("  targets already top 3 (need no link): {$set['top3_targets']}");
        $this->line("  sources over the word-scaled cap: {$set['over_cap_count']}");
        foreach ($set['over_cap'] as $o) {
            $this->line("    · {$o['title']} — projected {$o['projected']} (existing {$o['existing']} + adds {$o['adds']}) vs cap {$o['cap']} · {$o['words']} words");
        }
    }

    /** @param array<string, int> $map */
    private function kv(array $map): string
    {
        arsort($map);

        return collect($map)->map(fn (int $v, string $k): string => "{$k} {$v}")->implode(' · ');
    }
}
