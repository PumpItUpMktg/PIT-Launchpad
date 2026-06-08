<?php

namespace App\Integrations\Vision;

/**
 * The alt-text vision seam (committed — Claude vision). A dedicated interface
 * rather than an addition to the text ClaudeClient, so the shared text seam and
 * its fakes stay untouched. Tests bind a mock; no network call is made.
 */
interface VisionClient
{
    /**
     * Generate/verify alt text for an image, given optional context (the draft's
     * intended alt, the page subject).
     */
    public function describe(string $imageUrl, ?string $context = null): string;
}
