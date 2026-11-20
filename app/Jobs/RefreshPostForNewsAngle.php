<?php

namespace App\Jobs;

use App\ContentEngine\Coverage\NewsAngleRefresher;
use App\ContentEngine\Drafting\DraftFailure;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * The board's "Refresh existing post" on a candidate with a refresh / covered coverage verdict: the action
 * dispatches this and returns; the worker makes the one model call and folds the hook into the live post. The
 * post carries the "generating" marker and the candidate `meta.coverage.folding_at` until the refresh lands
 * (post → needs_review, candidate → rejected "folded into …") or fails (recorded on both rows, nothing changed).
 */
class RefreshPostForNewsAngle implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly string $postId,
        public readonly string $candidateId,
        public readonly ?string $actorId = null,
    ) {}

    /** Stamp both rows and enqueue (named enqueue, not queue — see GeneratePost). */
    public static function enqueue(Content $post, Content $candidate, ?string $actorId = null): void
    {
        $post->markGenerating();
        $meta = is_array($candidate->meta) ? $candidate->meta : [];
        $coverage = is_array($meta['coverage'] ?? null) ? $meta['coverage'] : [];
        $coverage['folding_at'] = Carbon::now()->toIso8601String();
        unset($coverage['fold_error']);
        $meta['coverage'] = $coverage;
        $candidate->forceFill(['meta' => $meta])->save();

        self::dispatch($post->id, $candidate->id, $actorId);
    }

    public function handle(NewsAngleRefresher $refresher): void
    {
        $post = Content::withoutGlobalScope(SiteScope::class)->find($this->postId);
        $candidate = Content::withoutGlobalScope(SiteScope::class)->find($this->candidateId);
        if ($post === null || $candidate === null) {
            return;
        }

        try {
            $refresher->refresh($post, $candidate);
        } catch (Throwable $e) {
            $this->recordFailure($post, $candidate, $e);
        }
    }

    /** A dead job (timeout / re-reservation) must not leave the rows stuck "Refreshing…". */
    public function failed(?Throwable $exception): void
    {
        $post = Content::withoutGlobalScope(SiteScope::class)->find($this->postId);
        $candidate = Content::withoutGlobalScope(SiteScope::class)->find($this->candidateId);
        $this->recordFailure($post, $candidate, $exception ?? new \RuntimeException('Refresh job failed.'));
    }

    private function recordFailure(?Content $post, ?Content $candidate, Throwable $e): void
    {
        $post?->recordDraftFailure(DraftFailure::fromException($e));
        if ($candidate !== null) {
            $meta = is_array($candidate->meta) ? $candidate->meta : [];
            $coverage = is_array($meta['coverage'] ?? null) ? $meta['coverage'] : [];
            unset($coverage['folding_at']);
            $coverage['fold_error'] = mb_substr($e->getMessage(), 0, 500);
            $meta['coverage'] = $coverage;
            $candidate->forceFill(['meta' => $meta])->save();
        }
    }
}
