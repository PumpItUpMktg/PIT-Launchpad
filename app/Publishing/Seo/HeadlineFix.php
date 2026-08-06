<?php

namespace App\Publishing\Seo;

use App\Models\Content;

/**
 * A proposed keyword-led rewrite of a page's three SEO surfaces (H1 / SEO title / meta description),
 * produced by {@see HeadlineKeywordFixer}. Immutable; the command renders it (dry-run) or applies it.
 */
final class HeadlineFix
{
    public function __construct(
        public readonly Content $page,
        public readonly string $keyword,
        public readonly string $heroKey,
        public readonly string $oldH1,
        public readonly string $newH1,
        public readonly string $oldTitle,
        public readonly string $newTitle,
        public readonly string $oldMeta,
        public readonly string $newMeta,
    ) {}

    public function changed(): bool
    {
        return $this->newH1 !== $this->oldH1
            || $this->newTitle !== $this->oldTitle
            || $this->newMeta !== $this->oldMeta;
    }
}
