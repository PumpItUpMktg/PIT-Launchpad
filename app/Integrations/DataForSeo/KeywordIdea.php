<?php

namespace App\Integrations\DataForSeo;

/**
 * A keyword expansion idea with its metrics attached — the unit the keyword-first corpus builder
 * accumulates. DataForSEO's related_keywords endpoint already returns volume/competition/difficulty on
 * each item; the strings-only {@see DataForSeoClient::relatedKeywords()} throws them away, so this DTO
 * (and {@see DataForSeoClient::relatedKeywordsWithMetrics()}) preserve them.
 */
final class KeywordIdea
{
    public function __construct(
        public readonly string $keyword,
        public readonly int $volume = 0,
        public readonly ?float $competition = null,
        public readonly ?int $difficulty = null,
    ) {}
}
