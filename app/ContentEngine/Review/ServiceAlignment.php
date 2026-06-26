<?php

namespace App\ContentEngine\Review;

use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Service;

/**
 * Does the drafted page actually talk about the service it's SUPPOSED to be about? A service page
 * carries its subject ({@see Content::$primary_service_id}, pinned at materialize). Before that pin
 * existed, a silo holding a cluster of siblings could hand the drafter the wrong one — and nothing
 * caught it before Approve (a /toilet-replacement page published with slow-drain / sewer-backup copy).
 * This is the catch: if the draft never mentions the intended service's distinctive term, the proof
 * step flags a likely service mismatch — a WARNING, not a block; the operator decides.
 *
 * Deliberately conservative: it only fires when there's a pinned subject AND the draft mentions NONE
 * of its distinctive terms, so a correctly-drafted page never trips it. Generic service-action words
 * (repair / installation / …) are stripped first, so it keys on the subject noun (toilet, sewer,
 * water heater) — the part that actually distinguishes one sibling from another.
 */
final class ServiceAlignment
{
    /** Generic service-action words shared across services — not distinctive enough to confirm subject. */
    private const GENERIC = [
        'replacement', 'replace', 'repair', 'repairs', 'installation', 'install', 'installs',
        'service', 'services', 'cleaning', 'clean', 'maintenance', 'inspection', 'tune', 'tuneup',
        'emergency', 'and', 'the', 'for', 'of', 'your', 'new',
    ];

    /**
     * @return array{checked: bool, aligned: bool, service: ?string, note: ?string}
     */
    public function check(Content $page): array
    {
        $clear = fn (?string $service = null): array => ['checked' => false, 'aligned' => true, 'service' => $service, 'note' => null];

        if ($page->primary_service_id === null) {
            return $clear();
        }

        $service = Service::withoutGlobalScope(SiteScope::class)->find($page->primary_service_id);
        if (! $service instanceof Service) {
            return $clear();
        }

        $name = (string) $service->name;
        $tokens = $this->tokens($name);
        $distinctive = array_values(array_diff($tokens, self::GENERIC));
        $needles = $distinctive !== [] ? $distinctive : $tokens;

        // No usable signal (e.g. an empty / all-generic name) — can't judge, don't cry wolf.
        if ($needles === []) {
            return $clear($name);
        }

        $haystack = $this->draftText($page);
        $aligned = false;
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                $aligned = true;
                break;
            }
        }

        return [
            'checked' => true,
            'aligned' => $aligned,
            'service' => $name,
            'note' => $aligned ? null : "This draft never mentions \"{$name}\" — it may have been generated for the wrong service.",
        ];
    }

    /** @return list<string> lowercased word tokens of 3+ chars */
    private function tokens(string $text): array
    {
        preg_match_all('/[a-z0-9]+/', strtolower($text), $matches);

        return array_values(array_filter($matches[0], fn (string $t) => strlen($t) >= 3));
    }

    /** The drafted prose to scan: title + every string slot value + SEO title/description. */
    private function draftText(Content $page): string
    {
        $parts = [(string) $page->title];

        $slots = is_array($page->slot_payload) ? $page->slot_payload : [];
        array_walk_recursive($slots, function ($value) use (&$parts): void {
            if (is_string($value)) {
                $parts[] = $value;
            }
        });

        $meta = is_array($page->meta) ? $page->meta : [];
        foreach (['title', 'description'] as $key) {
            if (isset($meta[$key]) && is_string($meta[$key])) {
                $parts[] = $meta[$key];
            }
        }

        return strtolower(implode(' ', $parts));
    }
}
