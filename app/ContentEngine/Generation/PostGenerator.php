<?php

namespace App\ContentEngine\Generation;

use App\ContentEngine\Drafting\DraftingEngine;
use App\ContentEngine\Drafting\DraftResult;
use App\Enums\ContentKind;
use App\Models\Content;
use App\Publishing\RenderCoordinator;
use InvalidArgumentException;

/**
 * The on-demand "Generate post" flow for a single routed candidate: draft it
 * (gated Sonnet — brand voice + local injection + source-following grounding),
 * then render its images (fal → R2) so the draft lands in review WITH its image.
 *
 * Pure connective tissue: it reuses the §6b drafting engine and the §2 render
 * pipeline unchanged. The Sonnet + fal calls are expensive, so this only ever
 * runs from an explicit operator action / command — never auto-fired.
 */
class PostGenerator
{
    public function __construct(
        private readonly DraftingEngine $engine,
        private readonly RenderCoordinator $renders,
    ) {}

    public function generate(Content $candidate, ?string $marketId = null, ?string $sourceBody = null): DraftResult
    {
        // The post lane must never touch a page. Drafting a candidate hard-codes
        // kind=Post (DraftRequest::forCandidate), so running this on a §4 pillar
        // (kind=page) would FLIP it to a post and publish it through the blog
        // template. Pages have their own flow (PageGenerator / "Generate page").
        if ($candidate->kind === ContentKind::Page) {
            throw new InvalidArgumentException(
                "Content [{$candidate->id}] is a page — generate it via the page flow (Generate page), not as a post."
            );
        }

        // Draft the candidate in place → needs_review.
        $result = $this->engine->draftCandidate($candidate, $marketId, $sourceBody);

        // Image at generate-time (step 2 of the flow): turn the drafted image_specs
        // into rendered R2 images now, so the reviewer sees the post with its image.
        // Idempotent — at publish, RenderCoordinator finds them already rendered.
        $this->renders->render($result->content);

        return $result;
    }
}
