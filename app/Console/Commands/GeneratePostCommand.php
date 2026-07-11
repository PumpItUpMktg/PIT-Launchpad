<?php

namespace App\Console\Commands;

use App\ContentEngine\BlogQueue\BlogTargetQueue;
use App\ContentEngine\BlogQueue\DirectedIntake;
use App\ContentEngine\Drafting\DraftFailedException;
use App\ContentEngine\Generation\PostGenerator;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;

class GeneratePostCommand extends Command
{
    protected $signature = 'launchpad:generate-post {content? : The routed candidate Content id} {--market= : Market id for local injection} {--directed : pull the top queued blog target instead of a candidate id} {--site= : the tenant (required with --directed)} {--silo= : limit the directed pull to one silo}';

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
}
