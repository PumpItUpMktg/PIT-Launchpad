<?php

namespace App\ContentEngine\Coverage;

use App\Models\Content;

final class NewsAngleRefreshResult
{
    /** @param  array{before: array<string, string>, after: array<string, string>, changed: list<string>}  $proposal */
    public function __construct(
        public readonly Content $post,
        public readonly Content $candidate,
        public readonly string $update,
        public readonly array $proposal,
    ) {}
}
