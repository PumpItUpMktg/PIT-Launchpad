<?php

namespace App\Support;

use App\Enums\PageType;
use App\Models\Content;

/**
 * The one place that builds a page's public URL — WITH the trailing slash WordPress uses for pretty
 * permalinks. Everything that emits or inspects a page URL (the canonical tag, OG url, the Live cards,
 * and Search Console index inspection) goes through here, so they all point at the FINAL, non-redirecting
 * URL. Building the slash-less form left the canonical pointing at a URL that 301-redirects to itself +
 * "/", which surfaced in GSC as "Excluded (redirect)".
 *
 * Home (empty slug) → "https://domain/". Missing domain → null (callers render the honest empty state).
 */
final class PublicUrl
{
    public static function for(?string $domain, ?string $slug): ?string
    {
        if (! is_string($domain) || trim($domain) === '') {
            return null;
        }

        $base = rtrim(trim($domain), '/');
        $path = trim((string) $slug, '/');

        return $base.'/'.($path === '' ? '' : $path.'/');
    }

    /**
     * The public URL for a Content row. The HOME page canonicalizes to the domain root ("/") — its slug
     * is "home" (the companion plugin's static-front-page marker), but WordPress serves it at "/", so a
     * "/home/" canonical would compete with the real front page (Google picks "/").
     */
    public static function forContent(?string $domain, Content $content): ?string
    {
        $slug = $content->page_type === PageType::Home ? '' : (string) $content->slug;

        return self::for($domain, $slug);
    }

    /**
     * A redirect TARGET in the same final form: a page path ("/hub/town-pa", or an absolute URL to one)
     * gains the trailing slash so the 301 lands on WordPress's real permalink in ONE hop instead of
     * bouncing through the slash-less form first. Anything that is not a page path is returned untouched:
     * an empty target (a 410 row has no destination), a file ("/sitemap.xml", "/guide.pdf"), a query string
     * or fragment (WordPress only canonicalizes a bare path), or the bare root.
     */
    public static function redirectTarget(string $target): string
    {
        $target = trim($target);
        if ($target === '' || $target === '/' || str_contains($target, '?') || str_contains($target, '#')) {
            return $target;
        }

        if (preg_match('~^(https?://[^/]+)(/.*)?$~i', $target, $m) === 1) {
            $path = $m[2] ?? '';

            return $m[1].($path === '' ? '/' : self::redirectTarget($path));
        }

        if (! str_starts_with($target, '/') || str_ends_with($target, '/')) {
            return $target;
        }

        $last = substr($target, (int) strrpos($target, '/') + 1);
        if (preg_match('/\\.[a-z0-9]{1,5}$/i', $last) === 1) {
            return $target; // a file, not a page
        }

        return $target.'/';
    }
}
