<?php

namespace App\Links;

use App\ContentEngine\Drafting\VoiceResolver;
use App\Enums\ContentKind;
use App\Enums\LinkProspectKind;
use App\Enums\PageType;
use App\Integrations\Claude\ClaudeClient;
use App\Models\Content;
use App\Models\Directory;
use App\Models\LinkProspect;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\OutreachKit;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Support\PublicUrl;
use Illuminate\Support\Str;
use Throwable;

/**
 * The outreach kit (§ Links): everything a person needs to get ONE link, drafted once. The facts — name,
 * address, phone, hours, the page to link — are copied verbatim from our data and never pass through the
 * model; the model writes only the prose: a short and a long description in the tenant's voice, category
 * suggestions, and a pitch (an email to a prospect, or the note for a directory form). When the model
 * fails, a plain fallback written from the NAP goes in instead and the kit says so, so the user is never
 * left with nothing to paste. Cheap scoring model; one call per kit; kept in outreach_kits.
 */
final class OutreachKitDrafter
{
    private const SHORT_MAX = 160;

    private const LONG_MAX = 750;

    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly VoiceResolver $voices,
    ) {}

    public function forDirectory(Location $location, Directory $directory, bool $redraft = false): OutreachKit
    {
        $site = Site::withoutGlobalScopes()->findOrFail($location->site_id);
        $existing = $this->existing($site, (string) $location->id, (string) $directory->domain);
        if ($existing !== null && ! $redraft) {
            return $existing;
        }
        $target = ['kind' => OutreachKit::DIRECTORY, 'domain' => (string) $directory->domain, 'name' => (string) $directory->name, 'submission_url' => $directory->submission_url ?: $directory->homepage_url];

        return $this->draft($site, $location, $target, $existing);
    }

    public function forProspect(LinkProspect $prospect, ?Location $location, bool $redraft = false): OutreachKit
    {
        $site = Site::withoutGlobalScopes()->findOrFail($prospect->site_id);
        $existing = $this->existing($site, $location?->id !== null ? (string) $location->id : null, (string) $prospect->domain);
        if ($existing !== null && ! $redraft) {
            return $existing;
        }
        $target = ['kind' => OutreachKit::PROSPECT, 'domain' => (string) $prospect->domain, 'name' => (string) $prospect->domain,
            'prospect_kind' => $prospect->kind->value, 'competitors' => $prospect->competitors];
        if ($prospect->kind->isLane()) {
            $target['page_url'] = $prospect->url;
            $target['offices_nearby'] = $prospect->offices ?? [];
            if ($prospect->kind === LinkProspectKind::Sponsorship && ($site->sponsorship_budget_min !== null || $site->sponsorship_budget_max !== null)) {
                $target['sponsorship_budget_per_year'] = ['min' => $site->sponsorship_budget_min, 'max' => $site->sponsorship_budget_max];
            }
            if ($prospect->kind === LinkProspectKind::DealerLocator) {
                $target['brands_carried'] = $site->brands ?? [];
            }
        }

        return $this->draft($site, $location, $target, $existing);
    }

    /** @param  array<string, mixed>  $target */
    private function draft(Site $site, ?Location $location, array $target, ?OutreachKit $existing): OutreachKit
    {
        $nap = $this->nap($site, $location);
        $linkUrl = $this->linkUrl($site, $location);
        $services = $this->services($site);
        $voice = $this->voices->toArray($this->voices->active((string) $site->id));

        $fallback = $this->fallback($site, $nap, $services, $target, $linkUrl);
        $modelOk = true;
        $copy = $fallback;
        try {
            $parsed = $this->parse($this->claude->complete($this->prompt($site, $nap, $services, $voice, $target, $linkUrl), $this->system()));
            if ($parsed !== null) {
                $copy = array_merge($fallback, $parsed);
            } else {
                $modelOk = false;
            }
        } catch (Throwable) {
            $modelOk = false;
        }

        $payload = [
            'kind' => $target['kind'],
            'target' => $target,
            'nap' => $nap,
            'link_url' => $linkUrl,
            'short_description' => Str::limit(self::oneLine((string) $copy['short_description']), self::SHORT_MAX, ''),
            'long_description' => Str::limit(trim((string) $copy['long_description']), self::LONG_MAX, ''),
            'categories' => array_slice(array_values(array_filter(array_map('strval', (array) $copy['categories']))), 0, 5),
            'pitch_subject' => self::oneLine((string) $copy['pitch_subject']),
            'pitch_body' => trim((string) $copy['pitch_body']),
        ];

        $kit = $existing ?? new OutreachKit;
        $kit->forceFill([
            'site_id' => $site->id,
            'location_id' => $location?->id,
            'target_kind' => $target['kind'],
            'target_domain' => $target['domain'],
            'payload' => $payload,
            'model' => $modelOk ? 'scoring' : null,
            'model_ok' => $modelOk,
            'drafted_at' => now(),
        ])->save();

        return $kit;
    }

    private function existing(Site $site, ?string $locationId, string $domain): ?OutreachKit
    {
        return OutreachKit::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('location_id', $locationId)->where('target_domain', $domain)->first();
    }

    /** @return array<string, mixed> */
    private function nap(Site $site, ?Location $location): array
    {
        if ($location === null) {
            // The company speaks for itself — from the corporate address. State-level bodies (an association,
            // a manufacturer) are approached once by the company, never once per office.
            return [
                'business_name' => (string) $site->brand_name,
                'address' => trim((string) $site->corporate_street),
                'city' => trim((string) $site->corporate_city), 'state' => trim((string) $site->corporate_state), 'postal' => trim((string) $site->corporate_postal_code),
                'phone' => trim((string) $site->phone),
                'hours' => null, 'categories' => [],
                'description_short' => null, 'description_long' => null,
            ];
        }
        $profile = LocationNapProfile::withoutGlobalScope(SiteScope::class)->where('location_id', $location->id)->first();
        if ($profile === null) {
            ['city' => $city, 'state' => $state] = $location->cityState();

            return [
                'business_name' => (string) $site->brand_name,
                'address' => trim((string) $location->address),
                'city' => $city, 'state' => $state, 'postal' => '',
                'phone' => trim((string) $location->phone),
                'hours' => null, 'categories' => [],
                'description_short' => null, 'description_long' => null,
            ];
        }

        return [
            'business_name' => (string) $profile->business_name,
            'address' => trim((string) $profile->address_1.' '.(string) ($profile->address_2 ?? '')),
            'city' => (string) $profile->city, 'state' => (string) $profile->state, 'postal' => (string) $profile->postal,
            'phone' => (string) $profile->phone_primary,
            'hours' => $profile->hours,
            'categories' => is_array($profile->categories) ? array_values(array_filter(array_map('strval', $profile->categories))) : [],
            'description_short' => $profile->description_short,
            'description_long' => $profile->description_long,
        ];
    }

    /** The page the link should land on: the office's own page, else the home page. */
    private function linkUrl(Site $site, ?Location $location): string
    {
        if ($location !== null) {
            $page = Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('kind', ContentKind::Page->value)
                ->where('page_type', PageType::Location->value)->where('location_id', $location->id)->first();
            $url = $page !== null ? PublicUrl::forContent($site->domain_url, $page) : null;
            if ($url !== null) {
                return $url;
            }
        }

        return PublicUrl::for($site->domain_url, '') ?? '';
    }

    /** @return list<array{name: string, description: string}> */
    private function services(Site $site): array
    {
        return Service::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->orderByDesc('is_most_profitable')->orderByDesc('is_growth_priority')->orderBy('name')->limit(8)->get()
            ->map(fn (Service $s): array => ['name' => (string) $s->name, 'description' => Str::limit(self::oneLine((string) ($s->description ?? '')), 140, '')])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $nap
     * @param  list<array{name: string, description: string}>  $services
     * @param  array<string, mixed>  $target
     * @return array{short_description: string, long_description: string, categories: list<string>, pitch_subject: string, pitch_body: string}
     */
    private function fallback(Site $site, array $nap, array $services, array $target, string $linkUrl): array
    {
        $names = implode(', ', array_slice(array_column($services, 'name'), 0, 4));
        $where = trim($nap['city'].($nap['state'] !== '' ? ', '.$nap['state'] : ''));
        $short = $nap['description_short'] ?: trim("{$nap['business_name']}: {$names}".($where !== '' ? " in {$where}" : '').'.');
        $long = $nap['description_long'] ?: trim("{$nap['business_name']} serves homeowners".($where !== '' ? " in and around {$where}" : '').($names !== '' ? " with {$names}." : '.').' Call '.$nap['phone'].' or visit '.$linkUrl.'.');
        $isProspect = ($target['kind'] ?? '') === OutreachKit::PROSPECT;

        return [
            'short_description' => $short,
            'long_description' => $long,
            'categories' => $nap['categories'],
            'pitch_subject' => $isProspect ? "{$nap['business_name']}".($where !== '' ? " — local {$where} partner" : '') : "Listing: {$nap['business_name']}",
            'pitch_body' => $isProspect
                ? "Hello,\n\nI run {$nap['business_name']}".($where !== '' ? " in {$where}" : '').". I noticed you list local home-service companies and wanted to ask whether you would add us: {$linkUrl}\n\n{$short}\n\nThank you,\n{$nap['business_name']}\n{$nap['phone']}"
                : "Business: {$nap['business_name']}\nAddress: {$nap['address']}, {$nap['city']}, {$nap['state']} {$nap['postal']}\nPhone: {$nap['phone']}\nWebsite: {$linkUrl}",
        ];
    }

    /** What the pitch asks for, by the kind of page it is going to. A sponsorship is a purchase with a link attached; a link is never bought on its own. */
    private static function laneBrief(?LinkProspectKind $kind): string
    {
        $common = ' Say who we are in one line and why it fits THEIR readers or members. No flattery, no SEO talk, never money for a link.';

        return match ($kind) {
            LinkProspectKind::Sponsorship => 'The pitch is a short email (under 170 words) to the organisation behind TARGET.page_url asking about their sponsorship packages: what levels they offer, what each includes, and whether sponsors are listed on their website with a link to LINK TO USE. If a budget range is given, say we are looking at that range; never name a figure otherwise. The offices listed as nearby are the ones the sponsorship is for.'.$common,
            LinkProspectKind::Membership => 'The pitch is a short email (under 170 words) asking how to join the organisation behind TARGET.page_url as a member business, and whether the member directory lists a website link (LINK TO USE). Mention the nearby office(s) as the location that would hold the membership.'.$common,
            LinkProspectKind::DealerLocator => 'The pitch is a short email (under 170 words) to the manufacturer or program behind TARGET.page_url asking to be listed in their dealer / installer locator for the brands we carry (TARGET.brands_carried), with LINK TO USE as our website. Ask what the listing requires (account number, proof of purchase volume, certification). The nearby offices are the service areas to list.'.$common,
            LinkProspectKind::JobBoard => 'The pitch is a short email (under 170 words) asking to be added as an employer partner on the job or training board behind TARGET.page_url: we hire in the trades named in SERVICES, would post openings there, and would like our employer profile to link to LINK TO USE.'.$common,
            LinkProspectKind::Press => 'The pitch is a short email (under 170 words) to the editor or news desk behind TARGET.page_url offering the business as a LOCAL SOURCE and a story, never asking for a link: name one specific, timely, newsworthy angle drawn from SERVICES and the season (storm and flooding readiness, a seasonal checklist for homeowners, a community event or sponsorship we are part of, a hiring or apprenticeship story), offer a quote or a short piece and local photos, and say the nearby office(s) are the ones in their coverage area. LINK TO USE may appear only as the business\'s website in the signature.'.$common,
            default => 'The pitch is a short email (under 170 words) to the person who runs the target site asking for one specific thing: a link to LINK TO USE from a page where it fits (a members/partners/resources page, a vendor list, a sponsor page). Say who we are in one line, why the link fits THEIR readers, and what we can offer back if it suits (a reciprocal mention, a sponsorship, a guest piece) — never money for a link. No flattery, no SEO talk.',
        };
    }

    /**
     * @param  array<string, mixed>  $nap
     * @param  list<array{name: string, description: string}>  $services
     * @param  array<string, mixed>  $voice
     * @param  array<string, mixed>  $target
     */
    private function prompt(Site $site, array $nap, array $services, array $voice, array $target, string $linkUrl): string
    {
        $isProspect = ($target['kind'] ?? '') === OutreachKit::PROSPECT;
        $json = fn (mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $parts = [];
        $parts[] = $isProspect
            ? 'Write the outreach kit to get ONE link to this business from the site below.'
            : "Write the listing copy for this business's listing on the directory below.";
        $parts[] = 'BRAND: '.$json(['brand' => $site->brand_name, 'tagline' => $site->tagline, 'site' => $site->domain_url]);
        $parts[] = 'OFFICE (facts — use exactly, never change a number or name): '.$json(array_filter($nap, fn ($v): bool => $v !== null && $v !== '' && $v !== []));
        $parts[] = 'SERVICES: '.$json($services);
        if ($voice !== []) {
            $parts[] = 'VOICE (write in this voice): '.$json($voice);
        }
        $parts[] = 'TARGET: '.$json($target);
        $parts[] = "LINK TO USE: {$linkUrl}";
        $parts[] = $isProspect
            ? self::laneBrief(LinkProspectKind::tryFrom((string) ($target['prospect_kind'] ?? '')))
            : 'The pitch is the short note a person pastes into the directory form\'s "about" or "message" field (under 80 words).';
        $parts[] = 'Rules: use only the facts given; no claims about awards, years in business, licenses or prices unless they appear above; plain words; no exclamation marks.';
        $parts[] = 'Return ONLY JSON: {"short_description":"<under 150 characters>","long_description":"<under 700 characters, 2-4 sentences>","categories":["<up to 5 directory categories>"],"pitch_subject":"<subject line>","pitch_body":"<the pitch>"}';

        return implode("\n\n", $parts);
    }

    private function system(): string
    {
        return 'You write listing copy and short outreach notes for a local service business. Use only the facts given. Return strict JSON only.';
    }

    /** @return array{short_description: string, long_description: string, categories: list<string>, pitch_subject: string, pitch_body: string}|null */
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
        $str = fn (string $k): string => is_string($d[$k] ?? null) ? trim($d[$k]) : '';
        $out = [
            'short_description' => $str('short_description'),
            'long_description' => $str('long_description'),
            'categories' => array_values(array_filter(array_map(fn ($c): string => is_string($c) ? trim($c) : '', is_array($d['categories'] ?? null) ? $d['categories'] : []))),
            'pitch_subject' => $str('pitch_subject'),
            'pitch_body' => $str('pitch_body'),
        ];

        return $out['short_description'] !== '' && $out['long_description'] !== '' && $out['pitch_body'] !== '' ? $out : null;
    }

    private static function oneLine(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }
}
