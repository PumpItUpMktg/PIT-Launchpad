<?php

namespace App\Jobs;

use App\Enums\SocialTrigger;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Social\SuggestionBoard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Puts a re-share proposal on the board — dispatched when a question refresh publishes. */
class SuggestPostShare implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $contentId, public readonly string $trigger, public readonly string $reason) {}

    public function handle(SuggestionBoard $board): void
    {
        $post = Content::withoutGlobalScope(SiteScope::class)->with(['site'])->find($this->contentId);
        if ($post === null) {
            return;
        }
        $meta = is_array($post->meta) ? $post->meta : [];
        $mark = is_array($meta['question_refresh'] ?? null) ? $meta['question_refresh'] : [];
        $snapshot = $mark !== [] ? array_filter([
            'question' => $mark['question'] ?? null,
            'also' => isset($mark['also']) && is_array($mark['also']) ? count($mark['also']) : null,
            'refreshed_at' => $mark['refreshed_at'] ?? null,
        ]) : null;
        $board->suggest($post, SocialTrigger::from($this->trigger), $this->reason, $snapshot);
    }
}
