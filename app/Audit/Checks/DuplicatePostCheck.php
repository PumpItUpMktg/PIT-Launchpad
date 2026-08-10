<?php

namespace App\Audit\Checks;

use App\Audit\AuditCheck;
use App\Audit\Finding;
use App\Audit\Severity;
use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * BLOG-001 (Class F) — a published blog post that duplicates another (same title) or carries a `-N`
 * dedupe suffix on its slug (the "...radon-risk" and "...radon-risk-2" pair). The candidate funnel is
 * supposed to dedupe before drafting; a shipped duplicate means a gate leaked. Flags each offender.
 */
final class DuplicatePostCheck implements AuditCheck
{
    public function id(): string
    {
        return 'BLOG-001';
    }

    public function defectClass(): string
    {
        return 'F';
    }

    public function severity(): string
    {
        return Severity::High;
    }

    public function title(): string
    {
        return 'Duplicate published post (repeated title or -N slug suffix)';
    }

    public function run(Site $site): array
    {
        $posts = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->whereNotNull('wp_post_id')
            ->orderBy('slug')
            ->get(['id', 'title', 'slug']);

        $out = [];
        $seenTitle = [];
        foreach ($posts as $post) {
            $title = mb_strtolower(trim((string) $post->title));
            if ($title !== '') {
                if (isset($seenTitle[$title])) {
                    $out[] = new Finding($site->id, (string) $site->brand_name, (string) $post->slug, 'duplicate post title: '.(string) $post->title);
                }
                $seenTitle[$title] = true;
            }
            if (preg_match('/-\d+$/', (string) $post->slug) === 1) {
                $out[] = new Finding($site->id, (string) $site->brand_name, (string) $post->slug, 'slug carries a -N dedupe suffix');
            }
        }

        return $out;
    }
}
