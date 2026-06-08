<?php

namespace App\Integrations\Serp;

/**
 * Capability role: a SERP/keyword provider. Implementations map raw vendor
 * output (DataForSEO, Ahrefs, Semrush, SerpApi, …) into the normalized
 * contract. Scoring, beatability, and tracking consume this interface only.
 */
interface SerpProvider
{
    public function metrics(string $query): KeywordMetrics;

    public function results(string $query): SerpResultSet;
}
