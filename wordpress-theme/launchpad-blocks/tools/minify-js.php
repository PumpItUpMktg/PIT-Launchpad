<?php
/**
 * Minify assets/area-map.js → assets/area-map.min.js. String- and comment-aware in one ordered pass:
 * `/* … *\/` and `// …` comments are dropped, but string literals ("…", '…', `…`) are protected FIRST
 * so a `//` inside a URL (e.g. "https://{s}.basemaps…") is never mistaken for a comment. Only whitespace
 * runs are collapsed (comments removed) — operators and statement boundaries are left intact, so the
 * result stays ASI-safe. The theme lazy-loads area-map.min.js; area-map.js stays the readable source.
 *
 *   php wordpress-theme/launchpad-blocks/tools/minify-js.php
 */

$dir = dirname(__DIR__).'/assets';
$src = $dir.'/area-map.js';
$out = $dir.'/area-map.min.js';

$js = file_get_contents($src);
if ($js === false) {
    fwrite(STDERR, "cannot read {$src}\n");
    exit(1);
}

ini_set('pcre.jit', '0');
ini_set('pcre.backtrack_limit', '10000000');
ini_set('pcre.recursion_limit', '10000000');

// Ordered alternation, leftmost match: a comment starting before a quote is consumed whole first; a
// string opened earlier consumes any `//` sitting inside it, so it never opens a comment.
//   1. /* … */ or // …    → dropped
//   2. "…" / '…' / `…`     → protected so their contents survive the whitespace collapse
$store = [];
$protect = static function (string $text) use (&$store): string {
    $token = "\0P".count($store)."\0";
    $store[$token] = $text;

    return $token;
};
$js = preg_replace_callback(
    '~/\*.*?\*/|//[^\n]*|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|`(?:\\\\.|[^`\\\\])*`~s',
    static fn (array $m): string => (str_starts_with($m[0], '/*') || str_starts_with($m[0], '//')) ? '' : $protect($m[0]),
    $js,
);
if ($js === null) {
    fwrite(STDERR, 'comment/string pass failed: '.preg_last_error_msg()."\n");
    exit(1);
}

// Collapse whitespace runs to a single space (operators/boundaries untouched → ASI-safe), then trim.
$js = trim((string) preg_replace('/\s+/', ' ', $js));

// Restore the protected literals.
$js = strtr($js, $store);

if (file_put_contents($out, $js) === false) {
    fwrite(STDERR, "cannot write {$out}\n");
    exit(1);
}
printf("minified %d → %d bytes (%s)\n", filesize($src), strlen($js), $out);
