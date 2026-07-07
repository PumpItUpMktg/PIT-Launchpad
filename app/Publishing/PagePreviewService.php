<?php

namespace App\Publishing;

use App\Enums\ContentSource;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Content;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Preview-push — the truest proof-step preview. Composes the CURRENT draft (the same meta-blob the
 * publish path sends) and pushes it to the blank WP instance as a WordPress DRAFT (status='draft';
 * the companion plugin maps any non-'published' status to a draft post), so the page renders with
 * the real Elementor template + brand kit without going live. The proof editor iframes the returned
 * preview URL.
 *
 * Distinct from publish: it NEVER flips Content.status (nothing goes live until Publish) and reuses
 * the images already rendered at generation (no re-render side effects). Idempotent by ULID — a
 * re-preview after an edit updates the same WP draft, so the iframe just refreshes.
 */
class PagePreviewService
{
    public function __construct(
        private readonly MetaBlobAssembler $assembler,
        private readonly WordpressClientFactory $wordpress,
    ) {}

    public function preview(Content $content): PreviewResult
    {
        if (! $content->hasDraft()) {
            return PreviewResult::unavailable('Generate the page first — there is no draft to preview yet.');
        }

        $site = Site::withoutGlobalScope(SiteScope::class)->findOrFail($content->site_id);
        $jobs = RenderJob::withoutGlobalScope(SiteScope::class)
            ->where('content_id', $content->id)
            ->get();

        // status='draft' → the plugin stores a WP draft (not live), then renders its preview.
        // preview:true → build the WHOLE page: data-gated sections with no data render a labeled
        // example placeholder so the operator sees the complete design and what's still missing.
        // The live publish path never sets this, so a placeholder can't reach the visitor-facing page.
        $payload = $this->assembler->assemble($content, $jobs, ContentSource::Generated, 'draft', preview: true);

        try {
            $response = $this->wordpress->forSite($site)->upsertContent($payload);
        } catch (WordpressException $e) {
            return PreviewResult::failed($e->getMessage());
        }

        $wpPostId = (int) ($response['wp_post_id'] ?? 0);
        $previewUrl = is_string($response['preview_url'] ?? null) && $response['preview_url'] !== ''
            ? $response['preview_url']
            : null;

        return PreviewResult::ready($wpPostId, $previewUrl);
    }
}
