<?php

namespace App\ContentEngine\Reconcile;

use App\ContentEngine\RelevanceScorer;
use App\Integrations\Claude\ClaudeClient;
use App\Models\Content;
use App\Models\Silo;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The opt-in last rung of the post→silo reconcile: a cheap (Haiku) read of a post's title, keyword and
 * description against the silo list, for the posts the deterministic rungs could not place. Those are the
 * titles made of words every silo shares — "How often should a sump pump run" on a sump-pump site — which
 * a rule cannot route without guessing but a reader places in a second (maintenance). One call per post,
 * routed only to a silo named in the list, never invented; any failure or unknown name leaves the post
 * unrouted. Bound to the scoring model like {@see RelevanceScorer}.
 */
final class AiSiloRouter
{
    public function __construct(private readonly ClaudeClient $claude) {}

    /**
     * The silo id the model chose, or null (no fit, an unknown name, or a failed call).
     *
     * @param  Collection<int, Silo>  $silos
     */
    public function route(Content $post, string $query, Collection $silos): ?string
    {
        if ($silos->isEmpty()) {
            return null;
        }
        try {
            $answer = $this->claude->complete($this->prompt($post, $query, $silos), $this->system());
        } catch (Throwable) {
            return null;
        }
        $name = $this->parse($answer);
        if ($name === null) {
            return null;
        }
        $silo = $silos->first(fn (Silo $s): bool => strcasecmp(trim((string) $s->name), $name) === 0);

        return $silo !== null ? (string) $silo->id : null;
    }

    /** @param  Collection<int, Silo>  $silos */
    private function prompt(Content $post, string $query, Collection $silos): string
    {
        $meta = is_array($post->meta) ? $post->meta : [];
        $seo = is_array($meta['seo'] ?? null) ? $meta['seo'] : [];
        $description = trim((string) ($seo['meta_description'] ?? ''));
        $names = $silos->map(fn (Silo $s): string => '- '.trim((string) $s->name))->implode("\n");

        return "File this blog post under the ONE topic it is mainly about.\n\n"
            ."Title: {$post->title}\n"
            .($query !== '' ? "Target keyword: {$query}\n" : '')
            .($description !== '' ? "Description: {$description}\n" : '')
            ."\nTopics (use the exact name; null if none fits):\n{$names}\n\n"
            .'Return ONLY JSON: {"topic":"<exact topic name|null>"}';
    }

    private function system(): string
    {
        return 'You file articles under a site\'s topics. Return strict JSON only. Choose null rather than a poor fit.';
    }

    private function parse(string $response): ?string
    {
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $decoded = json_decode(substr($response, $start, $end - $start + 1), true);
        $topic = is_array($decoded) ? ($decoded['topic'] ?? null) : null;

        return is_string($topic) && trim($topic) !== '' && strcasecmp(trim($topic), 'null') !== 0 ? trim($topic) : null;
    }
}
