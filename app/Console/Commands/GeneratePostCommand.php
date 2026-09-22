<?php

namespace App\Console\Commands;

use App\ContentEngine\BlogQueue\BlogTargetQueue;
use App\ContentEngine\BlogQueue\DirectedIntake;
use App\ContentEngine\Drafting\DraftFailedException;
use App\ContentEngine\Generation\PostGenerator;
use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;

class GeneratePostCommand extends Command
{
    protected $signature = 'launchpad:generate-post {content? : The routed candidate Content id} {--title= : Resolve the candidate by its exact title, or its original revival label, within --site (case-insensitive)} {--regenerate : Allow --title to overwrite an already-drafted post} {--market= : Market id for local injection} {--directed : pull the top queued blog target instead of a candidate id} {--site= : the tenant id or brand name (required with --directed or --title)} {--silo= : limit the directed pull to one silo}';

    protected $description = 'Generate a blog post from a routed candidate (or, with --directed, the top queued blog target): draft (Sonnet) + image (fal) → review queue.';

    public function handle(PostGenerator $generator): int
    {
        // DIRECTED lane (longtail relay): pull the top queued blog target for the tenant, draft an
        // article against that keyword through the SAME candidate path, and consume the target.
        $target = null;
        if ($this->option('directed')) {
            $site = Site::withoutGlobalScope(SiteScope::class)->find((string) $this->option('site'));
            if ($site === null) {
                $this->error('--directed needs --site=<id>.');

                return self::FAILURE;
            }

            $pulled = app(DirectedIntake::class)->pull($site, $this->option('silo') ?: null);
            if ($pulled === null) {
                $this->info('Blog target queue is empty — nothing to direct.');

                return self::SUCCESS;
            }
            [$target, $candidate] = [$pulled['target'], $pulled['candidate']];
            $this->line(sprintf('Directed target: "%s" (silo %s)', $target->keyword?->query, $target->silo_id));
        } elseif (trim((string) $this->option('title')) !== '') {
            $candidate = $this->byTitle(trim((string) $this->option('title')));
            if ($candidate === null) {
                return self::FAILURE;   // already explained
            }
        } else {
            $candidate = Content::query()->find($this->argument('content'));
        }

        if ($candidate === null) {
            $this->error('Candidate not found.');

            return self::FAILURE;
        }

        try {
            $result = $generator->generate($candidate, $this->option('market'));
        } catch (DraftFailedException $e) {
            $this->error('Draft failed — '.$e->getMessage());

            return self::FAILURE;
        }

        $content = $result->content;

        // Directed pull: the target is consumed by the draft (exclusive — never re-assigned).
        if ($target !== null) {
            app(BlogTargetQueue::class)->markDrafted($target->refresh(), $content);
        }

        $this->info(sprintf(
            "Generated '%s' → %s (silo %s).",
            $content->title,
            $content->status->value,
            $content->silo_id ?? '—',
        ));

        return self::SUCCESS;
    }

    /**
     * A candidate by title, inside one tenant — for the operator at a console who can see the Blog board
     * but not the ULIDs behind it. Exact match, case-insensitive, posts only.
     *
     * Ambiguity is refused, not resolved: two revival candidates on Sump Pump Gurus are both titled
     * "What Size Sump Pump Do I Need" and are different articles. Picking one silently would generate the
     * wrong family. The refusal lists each with its id so the operator can name the one they mean.
     */
    private function byTitle(string $title): ?Content
    {
        $arg = trim((string) $this->option('site'));
        $site = $arg === '' ? null : Site::withoutGlobalScope(SiteScope::class)
            ->where('id', $arg)->orWhere('brand_name', $arg)->first();
        if ($site === null) {
            $this->error('--title needs --site=<id or brand name>: titles are only unique inside a tenant.');

            return null;
        }

        // Match the current title OR the revival's original label. A candidate is titled from its winning
        // query at creation, and the drafter replaces that with a generated SEO title the moment it is
        // drafted — so the name an operator read off the board yesterday can stop resolving today. The
        // original label is kept in meta.revived_query and matches too.
        $needle = mb_strtolower($title);
        $matches = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->where(function ($q) use ($needle): void {
                $q->whereRaw('lower(title) = ?', [$needle])
                    ->orWhereRaw("lower(meta->>'revived_query') = ?", [$needle]);
            })
            ->orderBy('created_at')
            ->get();

        if ($matches->isEmpty()) {
            $this->error(sprintf('No post titled "%s" on %s.', $title, $site->brand_name));
            $this->listRevivals($site);

            return null;
        }
        if ($matches->count() > 1) {
            $this->error(sprintf('%d posts are titled "%s" on %s — name the one you mean by id:', $matches->count(), $title, $site->brand_name));
            foreach ($matches as $m) {
                $meta = is_array($m->meta) ? $m->meta : [];
                $from = (array) ($meta['revived_from_urls'] ?? []);
                $this->line(sprintf('  %s  %-12s %s', $m->id, $m->status->value, is_string($from[0] ?? null) ? 'replaces '.$from[0] : ''));
            }

            return null;
        }

        // Non-empty is guaranteed by the guard above.
        $match = $matches->first();
        if ($match->hasDraft() && ! $this->option('regenerate')) {
            $this->error(sprintf('"%s" is already drafted (%s). Generating again would overwrite that draft — pass --regenerate to mean it.',
                $match->title, $match->status->value));

            return null;
        }

        return $match;
    }

    /**
     * What IS here, when a title did not resolve — the revival candidates on this site with their current
     * titles, ids and state. The board never shows ids, so this is the only place an operator can get one.
     */
    private function listRevivals(Site $site): void
    {
        $rows = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->whereNotNull('meta->revived_from_urls')
            ->orderByDesc('created_at')
            ->limit(40)
            ->get();

        if ($rows->isEmpty()) {
            $this->line('  No revival candidates exist on this site. Create them with launchpad:revive-legacy-content --apply.');

            return;
        }

        $this->line(sprintf('  Revival candidates on %s (newest first) — pass the exact title, or the id as the argument:', $site->brand_name));
        foreach ($rows as $r) {
            $meta = is_array($r->meta) ? $r->meta : [];
            $label = is_string($meta['revived_query'] ?? null) && mb_strtolower($meta['revived_query']) !== mb_strtolower((string) $r->title)
                ? sprintf(' (was “%s”)', $meta['revived_query'])
                : '';
            $this->line(sprintf('  %s  %-12s %-9s %s%s', $r->id, $r->status->value, $r->hasDraft() ? 'drafted' : 'undrafted', $r->title, $label));
        }
    }
}
