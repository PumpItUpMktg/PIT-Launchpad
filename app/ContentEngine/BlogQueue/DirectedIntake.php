<?php

namespace App\ContentEngine\BlogQueue;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IntakeType;
use App\Models\BlogTarget;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * The DIRECTED intake lane (longtail relay §4): pull the top queued blog target and materialize
 * it as a directed candidate — a kind=post Content pinned to the target's silo + keyword, with an
 * angle hint that frames the searcher's question. The shared PostGenerator then drafts it exactly
 * like any candidate (grounded on the silo's services + voice; no news source), and the target is
 * consumed (drafted → published) so the queue never double-assigns.
 *
 * Explicit and operator-invoked only — this materializes ONE candidate per call; nothing here is
 * scheduled (generation is never automatic).
 */
class DirectedIntake
{
    public function __construct(private readonly BlogTargetQueue $queue) {}

    /**
     * The top queued target as a directed candidate (idempotent per target: an existing candidate
     * pinned to the same keyword is reused). Null when the queue is empty.
     *
     * @return array{target: BlogTarget, candidate: Content}|null
     */
    public function pull(Site $site, ?string $siloId = null): ?array
    {
        $target = $this->queue->top($site, $siloId);
        if ($target === null) {
            return null;
        }

        $keyword = $target->keyword;
        $query = trim((string) $keyword?->query);
        if ($query === '') {
            return null;
        }

        $existing = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->where('target_keyword_id', $target->keyword_id)
            ->first();
        if ($existing !== null) {
            return ['target' => $target, 'candidate' => $existing];
        }

        $candidate = Content::create([
            'site_id' => $site->id,
            'silo_id' => $target->silo_id,
            'matched_silo_id' => $target->silo_id,
            'kind' => ContentKind::Post,
            'intake_type' => IntakeType::Directed,
            'status' => ContentStatus::Candidate,
            'title' => Str::ucfirst($query),
            'slug' => $this->uniqueSlug($site->id, $query),
            'target_keyword_id' => $target->keyword_id,
            'angle_hint' => 'Directed article targeting the search "'.$query.'" — answer the searcher\'s question with practical, honest guidance grounded in the services provided.',
            'version' => 1,
        ]);

        return ['target' => $target, 'candidate' => $candidate];
    }

    private function uniqueSlug(string $siteId, string $title): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $n = 2;
        while (Content::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }
}
