<?php

namespace App\KeywordGenerator\Cluster;

use App\Integrations\Claude\ClaudeClient;
use App\KeywordGenerator\Corpus\KeywordNormalizer;
use App\Models\KeywordCorpus;

/**
 * The Claude pass over the raw geometry clusters (Part 2): name each cluster, MERGE near-duplicates,
 * SPLIT incoherent ones, and flag off-trade junk to drop — expressed as a regrouping (the model returns
 * output clusters, each a label + its member terms + an off-trade flag). Cheap Haiku pass (bound
 * contextually). Lossless: any corpus term the model forgets stays in its original grouping, and a
 * dropped/empty model response falls back to the geometry clusters labeled by their head term — the
 * labeler never loses a term or a cluster.
 */
final class ClusterLabeler
{
    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly KeywordNormalizer $normalizer,
    ) {}

    /**
     * @param  list<list<KeywordCorpus>>  $clusters
     * @return list<LabeledCluster>
     */
    public function label(array $clusters): array
    {
        if ($clusters === []) {
            return [];
        }

        /** @var array<string, KeywordCorpus> $byCanonical */
        $byCanonical = [];
        foreach ($clusters as $members) {
            foreach ($members as $member) {
                $byCanonical[$member->canonical] = $member;
            }
        }

        $data = $this->parse($this->claude->complete($this->prompt($clusters), $this->system()));

        $out = [];
        $assigned = [];
        foreach ((array) ($data['clusters'] ?? []) as $cluster) {
            if (! is_array($cluster)) {
                continue;
            }
            $members = [];
            foreach ((array) ($cluster['terms'] ?? []) as $term) {
                $canonical = $this->normalizer->canonical((string) $term);
                if (isset($byCanonical[$canonical]) && ! isset($assigned[$canonical])) {
                    $members[] = $byCanonical[$canonical];
                    $assigned[$canonical] = true;
                }
            }
            if ($members === []) {
                continue;
            }
            $label = trim((string) ($cluster['label'] ?? ''));
            $out[] = new LabeledCluster(
                $label !== '' ? $label : $members[0]->term,
                $members,
                (bool) ($cluster['off_trade'] ?? false),
            );
        }

        // Lossless fallback: any term the model didn't place stays with its original cluster-mates.
        foreach ($clusters as $members) {
            $remaining = array_values(array_filter($members, fn (KeywordCorpus $m): bool => ! isset($assigned[$m->canonical])));
            if ($remaining !== []) {
                $out[] = new LabeledCluster($remaining[0]->term, $remaining, false);
            }
        }

        return $out;
    }

    /**
     * @param  list<list<KeywordCorpus>>  $clusters
     */
    private function prompt(array $clusters): string
    {
        $blocks = [];
        foreach ($clusters as $i => $members) {
            $terms = implode(', ', array_map(fn (KeywordCorpus $m): string => $m->term, $members));
            $blocks[] = 'Cluster '.($i + 1).': '.$terms;
        }

        return "These are keyword clusters for a home-services business, grouped by semantic similarity.\n"
            ."Return a cleaned set of clusters:\n"
            ."- give each cluster a short, geo-neutral label naming what people are searching for (NOT the category name);\n"
            ."- MERGE clusters that are the same topic;\n"
            ."- SPLIT a cluster that mixes distinct topics;\n"
            ."- set off_trade=true for a cluster that is junk or unrelated to the trade (it will be dropped).\n"
            ."Keep every on-trade keyword in exactly one cluster. Do NOT invent keywords or add geo terms.\n\n"
            .implode("\n", $blocks)."\n\n"
            .'Respond with ONLY JSON: {"clusters":[{"label":"...","terms":["...","..."],"off_trade":false}]}.';
    }

    private function system(): string
    {
        return 'You are a search-demand analyst. Return strict JSON only. Labels are geo-neutral and reflect searcher intent.';
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $response): array
    {
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }
        $decoded = json_decode(substr($response, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }
}
