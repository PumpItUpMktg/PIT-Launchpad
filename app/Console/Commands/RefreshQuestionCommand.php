<?php

namespace App\Console\Commands;

use App\ContentEngine\Questions\QuestionRefresher;
use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use Illuminate\Console\Command;
use Throwable;

/**
 * The questions lane's Refresh for one marked post, synchronously (no FPM clock on the console):
 * one model call → direct answer at the top, Q&A section + FAQ schema at the end, title / SEO proposal
 * applied to the draft with the old values kept beside it → needs_review. Prints the proposal.
 */
class RefreshQuestionCommand extends Command
{
    protected $signature = 'launchpad:refresh-question {content : The post id (a published post the lane marked)}';

    protected $description = 'Refresh a post the questions lane marked: direct answer + Q&A (FAQ schema) + title/SEO proposal, into review. Synchronous.';

    public function handle(QuestionRefresher $refresher): int
    {
        $post = Content::withoutGlobalScope(SiteScope::class)->where('kind', ContentKind::Post->value)->find((string) $this->argument('content'));
        if ($post === null) {
            $this->error('No post with that id.');

            return self::FAILURE;
        }
        $mark = $post->meta['question_refresh'] ?? null;
        if (! is_array($mark)) {
            $this->error('That post carries no question mark — run launchpad:mine-questions <site> --execute first.');

            return self::FAILURE;
        }

        $this->line(sprintf('<info>%s</info> — refreshing for “%s”…', $post->title, (string) ($mark['query'] ?? '')));
        try {
            $result = $refresher->refresh($post);
        } catch (Throwable $e) {
            $this->error('Refresh failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('<info>Direct answer</info> (top of the body):');
        $this->line('  '.$result->answer);
        $this->line(sprintf('<info>Q&A section</info>: %d pair(s) — mirrored into the FAQ schema.', $result->faqCount));
        $this->newLine();
        $this->line('<info>Title / SEO proposal</info> (applied to the draft; the old values are kept for review):');
        foreach (['title' => 'Title (H1)', 'seo_title' => 'SEO title', 'meta_description' => 'Meta description'] as $key => $label) {
            $before = $result->proposal['before'][$key];
            $after = $result->proposal['after'][$key];
            $this->line($after === $before ? "  {$label}: unchanged" : "  {$label}: “{$before}” → “{$after}”");
        }
        $this->newLine();
        $this->line('Now in the review queue (needs_review). Approve → release → publish updates the same WordPress post.');

        return self::SUCCESS;
    }
}
