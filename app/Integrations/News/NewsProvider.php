<?php

namespace App\Integrations\News;

use DateTimeInterface;

/**
 * Capability role: a news source. Implementations map raw feed output into the
 * normalized NewsItem contract. Ingestion, scoring, and near-dup consume this
 * interface only — no vendor is committed.
 */
interface NewsProvider
{
    /**
     * @param  array<string, mixed>  $feedConfig
     * @return list<NewsItem>
     */
    public function fetch(array $feedConfig, ?DateTimeInterface $since = null): array;
}
