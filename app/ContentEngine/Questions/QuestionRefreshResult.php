<?php

namespace App\ContentEngine\Questions;

use App\Models\Content;

/**
 * What one Refresh produced on a marked post: the row (now in review), the title / SEO proposal with the
 * old values beside the new, and how many Q&As joined the body and the FAQ schema.
 *
 * @phpstan-type Proposal array{before: array{title: string, seo_title: string, meta_description: string}, after: array{title: string, seo_title: string, meta_description: string}, changed: list<string>}
 */
final class QuestionRefreshResult
{
    /** @param  Proposal  $proposal */
    public function __construct(
        public readonly Content $content,
        public readonly string $question,
        public readonly string $answer,
        public readonly array $proposal,
        public readonly int $faqCount,
    ) {}
}
