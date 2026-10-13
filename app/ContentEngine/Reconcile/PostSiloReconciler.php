<?php

namespace App\ContentEngine\Reconcile;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\KeywordGenerator\Bucketer;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;

/**
 * Re-connects blog posts to the CURRENT silo tree — the §B "Post → Silo" reconciler. A post's
 * WordPress category is assigned from `Content.silo_id` at publish; when §4 is rebuilt, that id points
 * at a deleted silo (or was never set) and the post publishes Uncategorized. This re-points each post
 * at a live silo, deterministically (no AI): keep a still-valid `silo_id`; else adopt a valid
 * `matched_silo_id`; else re-match by rule_set against the current silos ({@see Bucketer}, from the
 * post's title + target keyword); else by the silos' NAMES — the topic words a name carries that no
 * other silo shares ("Radon Mitigation" → radon; "Basement Waterproofing" → basement, waterproofing),
 * matched against the title, keyword and meta description. Rule_sets carry the commercial phrases the
 * keyword pipeline routes on ("sump pump installation"); an informational title ("Protect your basement
 * before flash floods strike") contains none of them, which is how Sump Pump Gurus had 601 posts unrouted
 * and 93 published ones under "More articles" on the blog hub. The name rung routes only when exactly ONE
 * silo wins; a tie or no distinguishing word leaves the post unrouted. A post that matches nothing has
 * BOTH ids cleared, so it publishes honestly Uncategorized rather than pushing a stale/wrong category.
 *
 * Idempotent: a post already on a current silo is left untouched. Returns the ids it re-pointed so the
 * caller (command / rebuild cascade) can repush just those.
 */
final class PostSiloReconciler
{
    public function __construct(
        private readonly Bucketer $bucketer,
        private readonly AiSiloRouter $ai,
    ) {}

    /** Words a silo name carries that say nothing about its topic. */
    private const TRADE_GENERIC = ['services', 'service', 'solutions', 'solution', 'installation', 'install', 'repair', 'repairs', 'replacement', 'maintenance', 'system', 'systems', 'company', 'local', 'and', 'the', 'for'];

    /**
     * `$aiPublished` adds the opt-in Haiku rung ({@see AiSiloRouter}) for PUBLISHED posts the deterministic
     * rungs could not place — one cheap call per such post, never for drafts or candidates.
     *
     * @return array{rerouted: list<string>, by_rule: int, by_name: int, by_ai: int, orphaned: int, orphaned_published: int, unchanged: int}
     */
    public function reconcile(Site $site, bool $aiPublished = false): array
    {
        $silos = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
        $liveIds = $silos->map(fn (Silo $s): string => (string) $s->id)->all();
        $nameTokens = $this->distinguishingTokens($silos);
        $byRule = 0;
        $byName = 0;
        $byAi = 0;
        $orphanedPublished = 0;

        $posts = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->get();

        $rerouted = [];
        $orphaned = 0;
        $unchanged = 0;

        foreach ($posts as $post) {
            // Already pinned to a current silo → nothing to do.
            if ($post->silo_id !== null && in_array((string) $post->silo_id, $liveIds, true)) {
                $unchanged++;

                continue;
            }

            $via = null;
            $target = null;
            if ($post->matched_silo_id !== null && in_array((string) $post->matched_silo_id, $liveIds, true)) {
                $target = (string) $post->matched_silo_id;
                $via = 'rule';
            } elseif (($hit = $this->bucketer->bucket($this->query($post), $silos)) !== null) {
                $target = (string) $hit->id;
                $via = 'rule';
            } elseif (($hit = $this->byName($this->query($post).' '.$this->description($post), $nameTokens)) !== null) {
                $target = $hit;
                $via = 'name';
            } elseif ($aiPublished && $post->status === ContentStatus::Published && ($hit = $this->ai->route($post, $this->query($post), $silos)) !== null) {
                $target = $hit;
                $via = 'ai';
            }

            if ($target !== null) {
                $post->forceFill(['silo_id' => $target, 'matched_silo_id' => $target])->save();
                $rerouted[] = (string) $post->id;
                match ($via) {
                    'rule' => $byRule++,
                    'name' => $byName++,
                    default => $byAi++,
                };

                continue;
            }
            if ($post->silo_id !== null || $post->matched_silo_id !== null) {
                // Unroutable: clear dangling ids so publish sends no stale category (honest Uncategorized).
                $post->forceFill(['silo_id' => null, 'matched_silo_id' => null])->save();
            }
            $orphaned++;
            $orphanedPublished += $post->status === ContentStatus::Published ? 1 : 0;
        }

        return [
            'rerouted' => $rerouted,
            'by_rule' => $byRule,
            'by_name' => $byName,
            'by_ai' => $byAi,
            'orphaned' => $orphaned,
            'orphaned_published' => $orphanedPublished,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * Each silo's DISTINGUISHING name tokens: its topic words minus the trade-generic ones, minus any word
     * that half or more of the site's silos share ("sump", "pump" on a sump-pump site say nothing about
     * which silo). A silo left with no distinguishing word can never win by name.
     *
     * @param  Collection<int, Silo>  $silos
     * @return array<string, list<string>> silo id → stemmed distinguishing tokens
     */
    private function distinguishingTokens(Collection $silos): array
    {
        $perSilo = [];
        $frequency = [];
        foreach ($silos as $silo) {
            $tokens = array_values(array_diff($this->tokens((string) $silo->name), self::TRADE_GENERIC));
            $perSilo[(string) $silo->id] = $tokens;
            foreach (array_unique($tokens) as $t) {
                $frequency[$t] = ($frequency[$t] ?? 0) + 1;
            }
        }
        $shared = max(2, (int) ceil($silos->count() / 2));
        foreach ($perSilo as $id => $tokens) {
            $perSilo[$id] = array_values(array_filter($tokens, fn (string $t): bool => ($frequency[$t] ?? 0) < $shared));
        }

        return $perSilo;
    }

    /**
     * The silo whose distinguishing words the text contains most — and only when exactly one silo is
     * ahead. A tie is left unrouted rather than guessed.
     *
     * @param  array<string, list<string>>  $nameTokens
     */
    private function byName(string $text, array $nameTokens): ?string
    {
        $words = array_fill_keys($this->tokens($text), true);
        $best = null;
        $bestScore = 0;
        $tied = false;
        foreach ($nameTokens as $id => $tokens) {
            $score = count(array_filter($tokens, fn (string $t): bool => isset($words[$t])));
            if ($score > $bestScore) {
                [$best, $bestScore, $tied] = [$id, $score, false];
            } elseif ($score === $bestScore && $score > 0) {
                $tied = true;
            }
        }

        return $bestScore > 0 && ! $tied ? $best : null;
    }

    /**
     * Lower-cased, stemmed word tokens (a crude plural fold: "pumps" → "pump", "floods" → "flood").
     *
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($text)) ?: [];
        $out = [];
        foreach ($words as $w) {
            if (mb_strlen($w) <= 2) {
                continue;
            }
            if (mb_strlen($w) > 4 && str_ends_with($w, 's') && ! str_ends_with($w, 'ss')) {
                $w = mb_substr($w, 0, -1);
            }
            $out[] = $w;
        }

        return array_values(array_unique($out));
    }

    private function description(Content $post): string
    {
        $meta = is_array($post->meta) ? $post->meta : [];
        $seo = is_array($meta['seo'] ?? null) ? $meta['seo'] : [];

        return trim((string) ($seo['meta_description'] ?? ''));
    }

    /** The routing query for a post: its title plus its target keyword (if any) — the rule_set haystack. */
    private function query(Content $post): string
    {
        $keyword = '';
        if ($post->target_keyword_id !== null) {
            $kw = Keyword::withoutGlobalScope(SiteScope::class)->find($post->target_keyword_id);
            $keyword = $kw !== null ? (string) $kw->query : '';
        }

        return trim((string) $post->title.' '.$keyword);
    }
}
