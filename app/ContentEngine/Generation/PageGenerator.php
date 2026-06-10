<?php

namespace App\ContentEngine\Generation;

use App\ContentEngine\Drafting\PageDraftingEngine;
use App\Models\Content;
use App\Publishing\RenderCoordinator;

/**
 * The on-demand "Generate page" flow for a single kind=page Content row: draft
 * its kit slots in place (gated Sonnet — intake-entity grounding + voice, schema-
 * validated), then render its image-slots (fal → R2) so the page lands in review
 * WITH its images. The post flow's analog (PostGenerator) — same review/publish
 * path downstream; only the draft engine differs. Expensive (Sonnet + fal), so it
 * runs only from an explicit operator action / command — never auto-fired.
 */
class PageGenerator
{
    public function __construct(
        private readonly PageDraftingEngine $engine,
        private readonly RenderCoordinator $renders,
    ) {}

    public function generate(Content $page): Content
    {
        // Draft the page in place → needs_review (or surfaced failure, no flip).
        $drafted = $this->engine->draftPage($page);

        // Render the drafted image-slots now, so review sees the page with images.
        // Idempotent — at publish, RenderCoordinator finds them already rendered.
        $this->renders->render($drafted);

        return $drafted;
    }
}
