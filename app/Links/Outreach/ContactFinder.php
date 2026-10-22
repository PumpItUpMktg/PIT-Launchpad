<?php

namespace App\Links\Outreach;

use App\KeywordGenerator\Beatability\CompetitorClassifier;
use App\Models\LinkProspect;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Find a person to write to (§ Links): read the opportunity page (or the home page), take any mailto:
 * and any address in the text, and if there is none follow the first "contact" link once. Addresses on
 * the prospect's own domain come first, then by role (sponsor…, member…, info, contact, hello, office).
 * Two GET requests at most, short timeouts, nothing is ever written to the target site.
 */
final class ContactFinder
{
    private const ROLE_ORDER = ['sponsor', 'member', 'partner', 'info', 'contact', 'hello', 'office', 'admin', 'director', 'president'];

    private const IGNORE = ['noreply', 'no-reply', 'donotreply', 'example.', 'sentry', 'wixpress', 'godaddy', 'wordpress', 'squarespace', '@2x', 'domain.com', 'yourdomain', 'email.com'];

    /** @return array{email: string|null, source: string|null, candidates: list<string>} */
    public function find(LinkProspect $prospect): array
    {
        $domain = strtolower((string) $prospect->domain);
        $start = trim((string) $prospect->url) !== '' ? (string) $prospect->url : 'https://'.$domain;
        $candidates = [];
        $source = null;

        $html = $this->fetch($start);
        if ($html !== null) {
            [$found, $src] = $this->extract($html, $domain);
            $candidates = $found;
            $source = $src;
            if ($candidates === []) {
                $contact = $this->contactLink($html, $start);
                if ($contact !== null) {
                    $html2 = $this->fetch($contact);
                    if ($html2 !== null) {
                        [$candidates, $source] = $this->extract($html2, $domain);
                    }
                }
            }
        }

        return ['email' => $candidates[0] ?? null, 'source' => $candidates !== [] ? $source : null, 'candidates' => $candidates];
    }

    private function fetch(string $url): ?string
    {
        try {
            $r = Http::timeout(max(2, (int) config('launchpad.links.outreach.contact_timeout', 8)))->connectTimeout(5)
                ->withUserAgent('Mozilla/5.0 (compatible; LaunchpadOutreach/1.0)')->accept('text/html')->get($url);
            if (! $r->successful()) {
                return null;
            }
            $body = $r->body();

            return $body !== '' ? mb_substr($body, 0, 1_500_000) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{0: list<string>, 1: string|null} */
    private function extract(string $html, string $domain): array
    {
        $seen = [];
        $source = null;
        if (preg_match_all('/mailto:([^"\'?<>\s]+)/i', $html, $m) > 0) {
            foreach ($m[1] as $raw) {
                $e = strtolower(trim(html_entity_decode(rawurldecode($raw))));
                if ($this->usable($e)) {
                    $seen[$e] = true;
                }
            }
            if ($seen !== []) {
                $source = 'mailto';
            }
        }
        $text = html_entity_decode(strip_tags($html));
        if (preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $m) > 0) {
            foreach ($m[0] as $raw) {
                $e = strtolower(trim($raw, " \t\n\r.,;:"));
                if ($this->usable($e) && ! isset($seen[$e])) {
                    $seen[$e] = true;
                    $source ??= 'page';
                }
            }
        }
        $list = array_keys($seen);
        usort($list, fn (string $a, string $b): int => [$this->onDomain($a, $domain) ? 0 : 1, $this->roleRank($a)] <=> [$this->onDomain($b, $domain) ? 0 : 1, $this->roleRank($b)]);

        return [array_slice($list, 0, 10), $source];
    }

    private function usable(string $email): bool
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/\.(png|jpe?g|gif|svg|webp|css|js)$/i', $email) === 1) {
            return false;
        }
        foreach (self::IGNORE as $bad) {
            if (str_contains($email, $bad)) {
                return false;
            }
        }

        return true;
    }

    private function onDomain(string $email, string $domain): bool
    {
        $at = CompetitorClassifier::registrable((string) substr($email, (int) strrpos($email, '@') + 1));

        return $at !== '' && $at === CompetitorClassifier::registrable($domain);
    }

    private function roleRank(string $email): int
    {
        $local = (string) strstr($email, '@', true);
        foreach (self::ROLE_ORDER as $i => $role) {
            if (str_contains($local, $role)) {
                return $i;
            }
        }

        return count(self::ROLE_ORDER);
    }

    /** The first link whose href or text says "contact", made absolute. */
    private function contactLink(string $html, string $base): ?string
    {
        if (preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $m, PREG_SET_ORDER) === 0) {
            return null;
        }
        foreach ($m as $a) {
            $href = html_entity_decode(trim($a[1]));
            $text = strtolower(strip_tags($a[2]));
            if (str_starts_with(strtolower($href), 'mailto:') || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'javascript:')) {
                continue;
            }
            if (str_contains(strtolower($href), 'contact') || str_contains($text, 'contact')) {
                return $this->absolute($href, $base);
            }
        }

        return null;
    }

    private function absolute(string $href, string $base): string
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
