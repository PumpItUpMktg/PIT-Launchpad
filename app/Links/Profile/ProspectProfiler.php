<?php

namespace App\Links\Profile;

use App\Enums\LinkProspectKind;
use App\Integrations\Claude\ClaudeClient;
use App\Models\LinkProspect;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The organisation behind a link prospect (§ Links): who they are, where, who to talk to, and what they
 * offer a sponsor or member — including the one answer link building turns on: is a website link part of
 * it. Two reads of their site at most (the opportunity page, then one hop to a packages / contact page),
 * the facts the page states pulled deterministically (PageFacts), then one cheap model call to write the
 * overview and structure the offer from the page text only. When the model does not answer, the facts
 * still land and the profile says so. Nothing the page does not state is filled in. Reads only.
 */
final class ProspectProfiler
{
    public const BENEFITS = ['website_link', 'logo_on_site', 'newsletter_mention', 'program_ad', 'signage', 'jersey', 'social_post', 'event_tickets', 'announcement', 'directory_listing', 'other'];

    public const LINK_OFFERED = 'offered';

    public const LINK_IMPLIED = 'implied';

    public const LINK_NOT_MENTIONED = 'not_mentioned';

    public function __construct(private readonly ClaudeClient $claude) {}

    /** Read the page(s), write the profile onto the prospect, return it. */
    public function read(LinkProspect $prospect): LinkProspect
    {
        $start = trim((string) $prospect->url) !== '' ? (string) $prospect->url : 'https://'.$prospect->domain;
        $html = $this->fetch($start);
        if ($html === null) {
            $prospect->forceFill(['profile_read_at' => now(), 'profile_error' => 'The page could not be read.'])->save();

            return $prospect;
        }
        $facts = PageFacts::from($html, $start);
        $pages = [['url' => $start, 'text' => $facts['text']]];

        // One hop: a packages / levels / join page for the offer, else a contact page for the people.
        $hopWords = $prospect->kind === LinkProspectKind::Membership
            ? ['membership', 'join', 'dues', 'benefits', 'contact']
            : ['sponsor', 'package', 'level', 'tier', 'partner', 'donat', 'support', 'contact'];
        $hop = PageFacts::linkMatching($facts['links'], $hopWords, $start);
        if ($hop !== null && rtrim($hop, '/') !== rtrim($start, '/')) {
            $html2 = $this->fetch($hop);
            if ($html2 !== null) {
                $f2 = PageFacts::from($html2, $hop);
                $pages[] = ['url' => $hop, 'text' => $f2['text']];
                $facts['emails'] = array_values(array_unique(array_merge($facts['emails'], $f2['emails'])));
                $facts['phones'] = array_values(array_unique(array_merge($facts['phones'], $f2['phones'])));
                $facts['social'] = $facts['social'] + $f2['social'];
                $facts['thumbnail'] ??= $f2['thumbnail'];
            }
        }

        $profile = [
            'name' => $facts['title'] !== '' ? $facts['title'] : $prospect->domain,
            'overview' => null,
            'org_kind' => null,
            'address' => null,
            'town' => null,
            'phone' => $facts['phones'][0] ?? null,
            'emails' => $facts['emails'],
            'contacts' => [],
            'social' => $facts['social'],
            'offer' => ['kind' => 'none', 'levels' => [], 'benefits' => [], 'website_link' => self::LINK_NOT_MENTIONED, 'deadline' => null, 'apply_url' => null, 'summary' => null],
            'pages_read' => array_column($pages, 'url'),
        ];

        $modelOk = false;
        try {
            $parsed = $this->parse($this->claude->complete($this->prompt($prospect, $facts, $pages), $this->system()));
            if ($parsed !== null) {
                $profile = array_replace($profile, $parsed);
                $modelOk = true;
            }
        } catch (Throwable) {
            $modelOk = false;
        }
        // The facts the page states always win over the model's reading of them.
        $profile['emails'] = $facts['emails'];
        $profile['phone'] = $profile['phone'] ?? ($facts['phones'][0] ?? null);
        $profile['social'] = $facts['social'];

        $prospect->forceFill([
            'profile' => $profile,
            'profile_read_at' => now(),
            'profile_model_ok' => $modelOk,
            'profile_error' => $modelOk ? null : 'The model did not answer; only the facts on the page are shown.',
            'thumbnail_url' => $facts['thumbnail'] !== null ? Str::limit($facts['thumbnail'], 2000, '') : $prospect->thumbnail_url,
        ]);
        if ($prospect->contact_email === null && $facts['emails'] !== []) {
            $prospect->forceFill(['contact_email' => $facts['emails'][0], 'contact_source' => 'page']);
        }
        $prospect->save();

        return $prospect;
    }

    /** One line a person can read on a card: "Gold $2,500 · Silver $1,000 · website link offered". */
    public static function offerLine(?array $profile): ?string
    {
        $offer = $profile['offer'] ?? null;
        if (! is_array($offer) || ($offer['kind'] ?? 'none') === 'none') {
            return null;
        }
        $parts = [];
        foreach (array_slice((array) ($offer['levels'] ?? []), 0, 4) as $l) {
            $name = trim((string) ($l['name'] ?? ''));
            $price = trim((string) ($l['price'] ?? ''));
            if ($name !== '' || $price !== '') {
                $parts[] = trim($name.' '.$price);
            }
        }
        $link = match ($offer['website_link'] ?? self::LINK_NOT_MENTIONED) {
            self::LINK_OFFERED => 'website link offered',
            self::LINK_IMPLIED => 'link implied (logo / listing on their site)',
            default => 'no link mentioned',
        };
        $parts[] = $link;

        return implode(' · ', $parts);
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

    /** @param  array<string, mixed>  $facts  @param  list<array{url: string, text: string}>  $pages */
    private function prompt(LinkProspect $prospect, array $facts, array $pages): string
    {
        $json = fn (mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $parts = [];
        $parts[] = 'Profile the organisation behind these page(s) for a local business deciding whether to approach them as a '.($prospect->kind === LinkProspectKind::Membership ? 'member' : 'sponsor').'.';
        $parts[] = 'FACTS ALREADY TAKEN FROM THE PAGE (do not restate; use them to name people and places): '.$json(['title' => $facts['title'], 'emails' => $facts['emails'], 'phones' => $facts['phones'], 'social' => array_keys($facts['social'])]);
        foreach ($pages as $i => $p) {
            $parts[] = 'PAGE '.($i + 1).' ('.$p['url'].") TEXT:\n".Str::limit($p['text'], $i === 0 ? 9000 : 5000, "\n[truncated]");
        }
        $parts[] = 'Rules: use only what the text says; leave a field empty / null when the page does not state it; never invent a price, a person or a benefit; prices as written ("$2,500", "$500/yr"). '
            .'benefits is drawn ONLY from this list: '.implode(', ', self::BENEFITS).'. '
            .'website_link is "offered" when the page says sponsors/members get a link to their website, "implied" when it says logos or listings appear on their website without saying "link", else "not_mentioned". '
            .'contacts are named people with a role on the page (sponsorship chair, membership director, executive director…), email only when it sits next to the name.';
        $parts[] = 'Return ONLY JSON: {"name":"<organisation name>","overview":"<2 sentences, plain words, what they are and do>","org_kind":"<league|chamber|association|nonprofit|school|government|manufacturer|business|media|other>","address":"<street, town, state zip or null>","town":"<town, ST or null>","contacts":[{"name":"","role":"","email":null}],"offer":{"kind":"<sponsorship|membership|donation|none>","levels":[{"name":"","price":"","includes":["<plain words>"]}],"benefits":["<from the list>"],"website_link":"<offered|implied|not_mentioned>","deadline":null,"apply_url":null,"summary":"<one sentence on what a sponsor/member gets, or null>"}}';

        return implode("\n\n", $parts);
    }

    private function system(): string
    {
        return 'You read a local organisation\'s web page and report, in strict JSON only, what it states about the organisation and its sponsorship or membership offer. You never add anything the page does not say.';
    }

    /** @return array<string, mixed>|null */
    private function parse(string $response): ?array
    {
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $d = json_decode(substr($response, $start, $end - $start + 1), true);
        if (! is_array($d)) {
            return null;
        }
        $str = fn (mixed $v, int $max = 300): ?string => is_string($v) && trim($v) !== '' ? Str::limit(trim($v), $max, '') : null;
        $offer = is_array($d['offer'] ?? null) ? $d['offer'] : [];
        $levels = [];
        foreach (array_slice(is_array($offer['levels'] ?? null) ? $offer['levels'] : [], 0, 8) as $l) {
            if (! is_array($l)) {
                continue;
            }
            $levels[] = ['name' => $str($l['name'] ?? null, 80) ?? '', 'price' => $str($l['price'] ?? null, 40) ?? '',
                'includes' => array_slice(array_values(array_filter(array_map(fn ($x) => $str($x, 120), is_array($l['includes'] ?? null) ? $l['includes'] : []))), 0, 8)];
        }
        $contacts = [];
        foreach (array_slice(is_array($d['contacts'] ?? null) ? $d['contacts'] : [], 0, 6) as $c) {
            if (! is_array($c) || $str($c['name'] ?? null, 80) === null) {
                continue;
            }
            $email = $str($c['email'] ?? null, 120);
            $contacts[] = ['name' => $str($c['name'], 80), 'role' => $str($c['role'] ?? null, 80), 'email' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? strtolower($email) : null];
        }
        $kind = $str($offer['kind'] ?? null, 20);
        $link = $str($offer['website_link'] ?? null, 20);

        return [
            'name' => $str($d['name'] ?? null, 120),
            'overview' => $str($d['overview'] ?? null, 400),
            'org_kind' => $str($d['org_kind'] ?? null, 30),
            'address' => $str($d['address'] ?? null, 160),
            'town' => $str($d['town'] ?? null, 80),
            'contacts' => $contacts,
            'offer' => [
                'kind' => in_array($kind, ['sponsorship', 'membership', 'donation'], true) ? $kind : 'none',
                'levels' => $levels,
                'benefits' => array_values(array_intersect(self::BENEFITS, array_map('strval', is_array($offer['benefits'] ?? null) ? $offer['benefits'] : []))),
                'website_link' => in_array($link, [self::LINK_OFFERED, self::LINK_IMPLIED], true) ? $link : self::LINK_NOT_MENTIONED,
                'deadline' => $str($offer['deadline'] ?? null, 60),
                'apply_url' => $str($offer['apply_url'] ?? null, 500),
                'summary' => $str($offer['summary'] ?? null, 300),
            ],
        ];
    }
}
