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
    protected $signature = 'launchpad:generate-post {content? : The routed candidate Content id} {--title= : Resolve the candidate by its exact title within --site (case-insensitive)} {--market= : Market id for local injection} {--directed : pull the top queued blog target instead of a candidate id} {--site= : the tenant id or brand name (required with --directed or --title)} {--silo= : limit the directed pull to one silo}';

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

        $matches = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->whereRaw('lower(title) = ?', [mb_strtolower($title)])
            ->orderBy('created_at')
            ->get();

        if ($matches->isEmpty()) {
            $this->error(sprintf('No post titled "%s" on %s.', $title, $site->brand_name));

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

        return $matches->first();
    }
}
