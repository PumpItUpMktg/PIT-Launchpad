<?php

namespace App\Links\Profile;

/**
 * The facts a page states on its face (§ Links), pulled without a model: emails, phones, social links,
 * the share image / icon for a thumbnail, the plain text. Deterministic, so a profile is never empty
 * when the model does not answer.
 */
final class PageFacts
{
    private const IGNORE_EMAIL = ['noreply', 'no-reply', 'donotreply', 'example.', 'sentry', 'wixpress', 'godaddy', 'wordpress', 'squarespace', '@2x', 'domain.com', 'yourdomain', 'email.com'];

    private const SOCIAL = ['facebook.com' => 'facebook', 'instagram.com' => 'instagram', 'linkedin.com' => 'linkedin', 'x.com' => 'x', 'twitter.com' => 'x', 'youtube.com' => 'youtube', 'tiktok.com' => 'tiktok'];

    /**
     * @return array{title: string, text: string, emails: list<string>, phones: list<string>, social: array<string, string>, thumbnail: string|null, links: list<array{href: string, text: string}>}
     */
    public static function from(string $html, string $baseUrl): array
    {
        $title = preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) === 1 ? self::clean(html_entity_decode(strip_tags($m[1]))) : '';
        $thumbnail = self::meta($html, 'og:image') ?? self::meta($html, 'twitter:image') ?? self::icon($html);
        $thumbnail = $thumbnail !== null ? self::absolute($thumbnail, $baseUrl) : null;

        $emails = [];
        if (preg_match_all('/mailto:([^"\'?<>\s]+)/i', $html, $mm) > 0) {
            foreach ($mm[1] as $raw) {
                $e = strtolower(trim(html_entity_decode(rawurldecode($raw))));
                if (self::usableEmail($e)) {
                    $emails[$e] = true;
                }
            }
        }
        $body = preg_replace('/<(script|style|noscript|svg)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = self::clean(html_entity_decode(strip_tags(preg_replace('/<(br|p|div|li|h[1-6]|tr|td|th)\b[^>]*>/i', "\n", $body) ?? $body)));
        if (preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $mm) > 0) {
            foreach ($mm[0] as $raw) {
                $e = strtolower(trim($raw, " \t\n\r.,;:"));
                if (self::usableEmail($e)) {
                    $emails[$e] = true;
                }
            }
        }
        $phones = [];
        if (preg_match_all('/(?<!\d)(?:\+?1[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}(?!\d)/', $text, $mm) > 0) {
            foreach ($mm[0] as $raw) {
                $digits = preg_replace('/\D/', '', $raw) ?? '';
                if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                    $digits = substr($digits, 1);
                }
                if (strlen($digits) === 10) {
                    $phones[$digits] = sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
                }
            }
        }
        $social = [];
        $links = [];
        if (preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $mm, PREG_SET_ORDER) > 0) {
            foreach ($mm as $a) {
                $href = html_entity_decode(trim($a[1]));
                $label = self::clean(html_entity_decode(strip_tags($a[2])));
                if (str_starts_with(strtolower($href), 'mailto:') || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'javascript:') || str_starts_with(strtolower($href), 'tel:')) {
                    continue;
                }
                $abs = self::absolute($href, $baseUrl);
                $host = strtolower((string) parse_url($abs, PHP_URL_HOST));
                foreach (self::SOCIAL as $domain => $key) {
                    if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                        $social[$key] ??= $abs;

                        continue 2;
                    }
                }
                if (count($links) < 80) {
                    $links[] = ['href' => $abs, 'text' => mb_substr($label, 0, 80)];
                }
            }
        }

        return [
            'title' => mb_substr($title, 0, 200),
            'text' => mb_substr($text, 0, 14000),
            'emails' => array_slice(array_keys($emails), 0, 10),
            'phones' => array_slice(array_values($phones), 0, 5),
            'social' => $social,
            'thumbnail' => $thumbnail,
            'links' => $links,
        ];
    }

    /** The first link whose text or path says one of the words, made absolute. @param list<array{href: string, text: string}> $links @param list<string> $words */
    public static function linkMatching(array $links, array $words, string $sameHostAs): ?string
    {
        $host = strtolower((string) parse_url($sameHostAs, PHP_URL_HOST));
        foreach ($links as $l) {
            $hay = strtolower($l['text'].' '.(string) parse_url($l['href'], PHP_URL_PATH));
            if (strtolower((string) parse_url($l['href'], PHP_URL_HOST)) !== $host) {
                continue;
            }
            foreach ($words as $w) {
                if (str_contains($hay, $w)) {
                    return $l['href'];
                }
            }
        }

        return null;
    }

    private static function meta(string $html, string $property): ?string
    {
        $p = preg_quote($property, '/');
        foreach (['/<meta\s[^>]*(?:property|name)=["\']'.$p.'["\'][^>]*content=["\']([^"\']+)["\']/i', '/<meta\s[^>]*content=["\']([^"\']+)["\'][^>]*(?:property|name)=["\']'.$p.'["\']/i'] as $re) {
            if (preg_match($re, $html, $m) === 1 && trim($m[1]) !== '') {
                return html_entity_decode(trim($m[1]));
            }
        }

        return null;
    }

    private static function icon(string $html): ?string
    {
        if (preg_match_all('/<link\s[^>]*rel=["\']([^"\']*icon[^"\']*)["\'][^>]*href=["\']([^"\']+)["\']/i', $html, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $l) {
                if (str_contains(strtolower($l[1]), 'apple-touch')) {
                    return html_entity_decode(trim($l[2]));
                }
            }

            return html_entity_decode(trim($m[0][2]));
        }
        if (preg_match('/<link\s[^>]*href=["\']([^"\']+)["\'][^>]*rel=["\'][^"\']*icon[^"\']*["\']/i', $html, $m) === 1) {
            return html_entity_decode(trim($m[1]));
        }

        return null;
    }

    private static function usableEmail(string $email): bool
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/\.(png|jpe?g|gif|svg|webp|css|js)$/i', $email) === 1) {
            return false;
        }
        foreach (self::IGNORE_EMAIL as $bad) {
            if (str_contains($email, $bad)) {
                return false;
            }
        }

        return true;
    }

    private static function clean(string $s): string
    {
        $s = preg_replace('/[ \t\x{00A0}]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s*\n\s*/', "\n", $s) ?? $s;

        return trim(preg_replace('/\n{2,}/', "\n", $s) ?? $s);
    }

    public static function absolute(string $href, string $base): string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }
        $scheme = (string) (parse_url($base, PHP_URL_SCHEME) ?: 'https');
        $host = (string) parse_url($base, PHP_URL_HOST);
        if (str_starts_with($href, '//')) {
            return $scheme.':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return "{$scheme}://{$host}{$href}";
        }
        $path = (string) (parse_url($base, PHP_URL_PATH) ?: '/');
        $dir = rtrim((string) substr($path, 0, (int) strrpos($path, '/') + 1), '/');

        return "{$scheme}://{$host}{$dir}/{$href}";
    }
}
