<?php
/**
 * Minify assets/theme.css → assets/theme.min.css. String-, url()- and comment-aware in a single pass:
 * comments are dropped and string / url(…) literals (incl. data: URIs) are placeheld BEFORE the
 * whitespace collapse, then restored — so nothing inside a literal is corrupted, and a stray quote or
 * apostrophe inside a prose comment (e.g. "what's included") can never swallow content across the
 * comment boundary. Regenerate after editing theme.css:
 *
 *   php wordpress-theme/launchpad-blocks/tools/minify-css.php
 *
 * The theme enqueues theme.min.css; theme.css stays the readable source of truth.
 */

$dir = dirname(__DIR__).'/assets';

// Every readable source stylesheet the theme serves → its .min build. theme.css is the design system;
// vendor/leaflet is the map lib (its shipped .css is unminified). Both are enqueued minified.
$targets = [
    $dir.'/theme.css' => $dir.'/theme.min.css',
    $dir.'/vendor/leaflet/leaflet.css' => $dir.'/vendor/leaflet/leaflet.min.css',
];

// PCRE limits: the alternation below scans the whole ~58KB file; the JIT stack overruns on it, and the
// default backtrack/recursion limits are comfortably clear once JIT is off.
ini_set('pcre.jit', '0');
ini_set('pcre.backtrack_limit', '10000000');
ini_set('pcre.recursion_limit', '10000000');

foreach ($targets as $src => $out) {
    if (! is_file($src)) {
        continue;
    }
    minify_css_file($src, $out);
}

/**
 * Minify one stylesheet. One ordered pass: the scan is left-to-right and alternatives are tried in
 * order, so a comment starting before a quote is consumed as a whole comment first — the quote inside
 * it never opens a string match.
 *   1. comments               → dropped
 *   2. "…" / '…'            → protected (placeheld) so its whitespace survives the collapse
 *   3. url( … )             → protected (data: URIs, font paths)
 */
function minify_css_file(string $src, string $out): void
{
    $css = file_get_contents($src);
    if ($css === false) {
        fwrite(STDERR, "cannot read {$src}\n");
        exit(1);
    }

    $store = [];
    $protect = static function (string $text) use (&$store): string {
        $token = "\0P".count($store)."\0";
        $store[$token] = $text;

        return $token;
    };
    $css = preg_replace_callback(
        '~/\*.*?\*/|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|url\((?:[^)"\']|"[^"]*"|\'[^\']*\')*\)~is',
        static fn (array $m): string => str_starts_with($m[0], '/*') ? '' : $protect($m[0]),
        $css,
    );
    if ($css === null) {
        fwrite(STDERR, 'protect/comment pass failed: '.preg_last_error_msg()."\n");
        exit(1);
    }

    // Collapse whitespace and drop it around structural punctuation.
    $css = preg_replace('/\s+/', ' ', $css);
    $css = preg_replace('/\s*([{}:;,>~+])\s*/', '$1', (string) $css);
    $css = str_replace(';}', '}', (string) $css);   // drop the last semicolon in a block
    $css = trim((string) $css);

    // Restore the protected literals.
    $css = strtr($css, $store);

    if (file_put_contents($out, $css) === false) {
        fwrite(STDERR, "cannot write {$out}\n");
        exit(1);
    }
    printf("minified %d → %d bytes (%s)\n", filesize($src), strlen($css), $out);
}
