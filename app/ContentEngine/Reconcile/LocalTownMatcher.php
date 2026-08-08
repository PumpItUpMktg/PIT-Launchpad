<?php

namespace App\ContentEngine\Reconcile;

/**
 * Finds the towns a blog post genuinely names, with two guards the naive whole-word scan lacked:
 *
 *  - **Ambiguous-name false positives.** Many municipality names are also common English words
 *    ("a good **deal**", "the basement **wall**", "this **spring**", "water**falls**"). A bare mention
 *    of one of those must NOT tag the town — it only counts when the copy qualifies it with its state
 *    ("Deal, NJ"). Unambiguous names (Hoboken, Asbury Park) still match on a plain whole-word mention.
 *  - **Substring precedence.** A specific town wins the span it occupies, so "Brunswick" can't tag off
 *    the "Brunswick" inside "New Brunswick" — longest names are matched first and claim their span.
 *
 * Returns each match with its first character offset so {@see LocalTownCoherence} can keep the dominant
 * county+state cluster (a blog stays relevant to ONE locale, not twenty scattered towns).
 */
final class LocalTownMatcher
{
    /**
     * Single-word municipality names that are also common English words — a bare mention must not tag
     * them; they count only when state-qualified. Extend per deployment via config `launchpad.ambiguous_town_names`.
     *
     * @var list<string>
     */
    private const AMBIGUOUS = [
        'deal', 'wall', 'spring', 'springs', 'falls', 'chester', 'orange', 'dover', 'warren',
        'washington', 'franklin', 'clinton', 'hope', 'liberty', 'independence', 'oxford', 'hamilton',
        'madison', 'jefferson', 'union', 'summit', 'hill', 'hills', 'brick', 'wayne', 'plains',
        'middle', 'milford', 'greenwich', 'hopewell', 'raritan', 'middlesex', 'somerset', 'freedom',
    ];

    /**
     * @param  list<array{key: string, display: string, name: string, county: ?string, state: ?string}>  $towns
     * @return list<array{key: string, display: string, county: ?string, state: ?string, pos: int}>
     */
    public static function scan(string $text, array $towns): array
    {
        $hay = ' '.mb_strtolower(trim(strip_tags($text))).' ';
        $ambiguous = self::ambiguousSet();

        // Longest names first so a specific town claims its span before a shorter substring town.
        usort($towns, fn (array $a, array $b): int => mb_strlen($b['name']) <=> mb_strlen($a['name']));

        $out = [];
        $claimed = []; // list<array{0:int,1:int}> matched spans, so a substring town can't re-match inside one
        foreach ($towns as $t) {
            $name = mb_strtolower(trim($t['name']));
            if ($name === '') {
                continue;
            }
            $pos = self::firstMention($hay, $name, $t['state'], in_array($name, $ambiguous, true), $claimed);
            if ($pos === null) {
                continue;
            }
            $claimed[] = [$pos, $pos + strlen($name)];
            $out[] = ['key' => $t['key'], 'display' => $t['display'], 'county' => $t['county'], 'state' => $t['state'], 'pos' => $pos];
        }

        usort($out, fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);

        return $out;
    }

    /**
     * First whole-word offset of $name not already inside a claimed span; ambiguous names require the
     * state qualifier ("name, ST" / "name ST") and are skipped entirely when the state is unknown.
     *
     * @param  list<array{0:int,1:int}>  $claimed
     */
    private static function firstMention(string $hay, string $name, ?string $state, bool $ambiguous, array $claimed): ?int
    {
        $q = preg_quote($name, '/');
        if ($ambiguous) {
            $st = $state !== null ? trim($state) : '';
            if ($st === '') {
                return null; // can't disambiguate a common-word town without its state
            }
            $pattern = '/(?<![\p{L}\p{N}])'.$q.',?\s+'.preg_quote(mb_strtolower($st), '/').'(?![\p{L}\p{N}])/u';
        } else {
            $pattern = '/(?<![\p{L}\p{N}])'.$q.'(?![\p{L}\p{N}])/u';
        }

        $offset = 0;
        while (preg_match($pattern, $hay, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $at = (int) $m[0][1];
            $inside = false;
            foreach ($claimed as $span) {
                if ($at >= $span[0] && $at < $span[1]) {
                    $inside = true;
                    break;
                }
            }
            if (! $inside) {
                return $at;
            }
            $offset = $at + 1;
        }

        return null;
    }

    /** @return list<string> */
    private static function ambiguousSet(): array
    {
        $extra = (array) config('launchpad.ambiguous_town_names', []);
        $all = array_merge(self::AMBIGUOUS, array_map(fn ($s): string => mb_strtolower(trim((string) $s)), $extra));

        return array_values(array_unique($all));
    }
}
