<?php

namespace App\Citations;

use App\Citations\Ui\LocationWorkspace;
use App\Citations\Ui\WorkspaceRow;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\BusinessHours;
use App\Support\CurrentSite;

/**
 * The citation tracker a freelancer works from, per office (§ Citations): the canonical NAP block the
 * submissions must match character for character, then every applicable directory with what the monthly
 * scan knows — Live / Mismatch / Submitted / Missing / Needs review / Not scanned — the live listing URL
 * when one was found, the page to create a listing on when none was, and what to watch for. The same rows
 * the Citations board shows, in the tracker's own columns, so the scan fills the sheet instead of a person.
 */
final class CitationSheetExport
{
    /** The tracker's directory columns, in its order. */
    public const COLUMNS = ['Directory', 'Status', 'Live URL', 'Username/Email', 'Password', 'Date Submitted', 'Verification Method', 'Notes'];

    public function __construct(private readonly LocationWorkspace $workspace) {}

    /**
     * @return array{site: array{brand: string, domain: string}, locations: list<array<string, mixed>>}
     */
    public function forSite(Site $site, ?string $locationId = null): array
    {
        $locations = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->when($locationId !== null, fn ($q) => $q->whereKey($locationId))
            ->orderBy('created_at')->get();
        $previous = CurrentSite::id();
        CurrentSite::set((string) $site->id);
        try {
            $out = [];
            foreach ($locations as $location) {
                $out[] = $this->forLocation($site, $location);
            }
        } finally {
            if ($previous !== null) {
                CurrentSite::set($previous);
            }
        }

        return ['site' => ['brand' => (string) $site->brand_name, 'domain' => (string) $site->domain_url], 'locations' => $out];
    }

    /** @return array<string, mixed> */
    public function forLocation(Site $site, Location $location): array
    {
        $profile = LocationNapProfile::withoutGlobalScope(SiteScope::class)->where('location_id', $location->id)->first();
        ['city' => $city, 'state' => $state] = $location->cityState();

        $nap = [
            'business_name' => trim((string) ($profile?->business_name ?: $site->brand_name)),
            'street' => trim((string) ($profile?->address_1 ?: $location->address)),
            'suite' => trim((string) $profile?->address_2),
            'city' => trim((string) ($profile?->city ?: $city)),
            'state' => strtoupper(trim((string) ($profile?->state ?: $state))),
            'zip' => trim((string) $profile?->postal),
            'phone' => trim((string) ($profile?->phone_primary ?: $location->phone)),
            'website' => trim((string) ($profile?->website_url ?: $site->domain_url)),
            'categories' => implode('; ', array_values(array_filter(array_map('strval', is_array($profile?->categories) ? $profile->categories : [])))),
            'hours' => self::hoursLine(is_array($profile?->hours) ? $profile->hours : []),
            'description' => trim((string) $profile?->description_short),
            'verification_email' => trim((string) $profile?->verification_email),
        ];

        $rows = [];
        foreach ($this->workspace->forLocation($location)['rows'] as $row) {
            $rows[] = self::row($row);
        }

        return [
            'id' => (string) $location->id,
            'name' => (string) $location->name,
            'has_profile' => $profile !== null,
            'nap' => $nap,
            'directories' => $rows,
        ];
    }

    /** One tracker row: the status in the tracker's words, the live URL, and the notes a freelancer needs. @return array<string, mixed> */
    private static function row(WorkspaceRow $r): array
    {
        $status = match ($r->chip['key']) {
            'live' => 'Live',
            'mismatch' => 'Live — NAP mismatch, fix',
            'submitted' => 'Submitted',
            'missing' => 'Missing',
            'needs_review' => 'Needs review',
            'stalled' => 'Stalled',
            'rejected' => 'Rejected',
            'not_relevant' => 'Not relevant',
            default => 'Not scanned',
        };
        // "Live URL" is a promise the freelancer acts on: only a listing the scan still finds live goes there.
        // A URL on a missing row is where the listing was last seen (the page stopped answering) and a URL
        // on a needs-review row could not be confirmed as this office's — both are notes, never "live".
        $liveUrl = in_array($r->chip['key'], ['live', 'mismatch', 'submitted'], true) ? $r->listingUrl : null;
        $notes = [];
        $notes[] = ucfirst($r->tierLabel).' value'.($r->isLocal ? ', local' : ', national');
        if ($r->listedFor !== null) {
            $notes[] = 'the listing found belongs to '.$r->listedFor;
        }
        if ($r->chip['key'] === 'mismatch' && $r->napMatchSummary !== '') {
            $notes[] = $r->napMatchSummary;
        }
        if ($liveUrl === null && $r->listingUrl !== null) {
            $notes[] = $r->chip['key'] === 'needs_review'
                ? 'scan found '.$r->listingUrl.' but could not confirm it is this office\'s — check before creating another'
                : 'last seen at '.$r->listingUrl.' (the page no longer answers) — confirm before re-creating';
        }
        if ($r->createUrl !== null && $liveUrl === null) {
            $notes[] = ($r->createLabel ?? 'Create').': '.$r->createUrl;
        }
        if ($r->lastCheckedAt !== null) {
            $notes[] = 'checked '.$r->lastCheckedAt->toDateString();
        }

        return [
            'directory' => $r->directoryName,
            'homepage' => $r->homepageUrl,
            'status' => $status,
            'status_key' => $r->chip['key'],
            'live_url' => $liveUrl,
            'create_url' => $r->createUrl,
            'submittable' => $r->submittable,
            'tier' => $r->tierLabel,
            'local' => $r->isLocal,
            'listed_for' => $r->listedFor,
            'last_checked' => $r->lastCheckedAt?->toDateString(),
            'notes' => implode(' · ', $notes),
        ];
    }

    /** "Mon–Fri 8:00–18:00; Sat 9:00–13:30" from whatever shape the profile holds; the raw text when it is not structured. @param list<mixed> $hours */
    public static function hoursLine(array $hours): string
    {
        // A plain list of strings is shown as written. Anything else is the stored per-day map (or one of the
        // legacy shapes BusinessHours::normalize repairs), collapsed the way the site shows it to customers:
        // "Monday – Friday, 8am – 6pm; Saturday, 9am – 1:30pm" / "Monday – Sunday, Open 24 hours".
        if ($hours !== [] && array_is_list($hours) && array_filter($hours, 'is_string') === $hours) {
            return implode('; ', array_values(array_filter(array_map('trim', $hours), fn (string $p): bool => $p !== '')));
        }

        return implode('; ', array_map(fn (array $row): string => $row['label'].', '.$row['value'], BusinessHours::displayRows($hours)));
    }

    /** The tracker tab as CSV: the NAP block in the tab's own two-column layout, a blank line, then the directory table. @param array<string, mixed> $location */
    public static function csv(array $location): string
    {
        $h = fopen('php://temp', 'r+');
        if ($h === false) {
            return '';
        }
        $nap = $location['nap'];
        fputcsv($h, ['Master NAP Information']);
        foreach ([
            'Business / Location Name' => $nap['business_name'], 'Street Address' => $nap['street'], 'Suite/Unit' => $nap['suite'], 'City' => $nap['city'],
            'State' => $nap['state'], 'ZIP Code' => $nap['zip'], 'Phone Number' => $nap['phone'], 'Website URL' => $nap['website'],
            'Business Categories' => $nap['categories'], 'Hours of Operation' => $nap['hours'], 'Short Bio/Description' => $nap['description'],
        ] as $label => $value) {
            fputcsv($h, [$label, (string) $value]);
        }
        fputcsv($h, []);
        fputcsv($h, self::COLUMNS);
        foreach ($location['directories'] as $d) {
            fputcsv($h, [$d['directory'], $d['status'], (string) ($d['live_url'] ?? ''), '', '', '', '', $d['notes']]);
        }
        rewind($h);

        return (string) stream_get_contents($h);
    }
}
