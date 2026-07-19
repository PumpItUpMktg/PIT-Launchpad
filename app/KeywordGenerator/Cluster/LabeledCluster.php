<?php

namespace App\KeywordGenerator\Cluster;

use App\Models\KeywordCorpus;

/**
 * A cluster after the Claude labeling pass: a human name + its member corpus rows, plus whether Claude
 * flagged it off-trade (to drop rather than derive a silo from). Merges/splits are already applied —
 * this is the regrouped, named result.
 */
final class LabeledCluster
{
    /**
     * @param  list<KeywordCorpus>  $members
     */
    public function __construct(
        public readonly string $label,
        public readonly array $members,
        public readonly bool $offTrade = false,
    ) {}
}
