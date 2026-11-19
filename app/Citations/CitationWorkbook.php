<?php

namespace App\Citations;

use App\Models\LocationNapProfile;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteBranding;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The whole citation work order as ONE workbook (§ Citations): a Read-me tab with the ground rules and an
 * index of the offices, an "All offices NAP" tab (one row per office, every field the submissions must match),
 * then one tab per office — its NAP block and assets at the top, the directory table below in the tracker's
 * own columns with what the scan found today (status, the current listing URL, how the found listing
 * differs, where to create one) and the columns the builder fills (Live URL, login, date, verification,
 * notes). Built from {@see CitationSheetExport}, so it is the Citations board as a spreadsheet — the tab a
 * builder fills comes back through the CSV import unchanged in its header names.
 */
final class CitationWorkbook
{
    /** The per-office directory table, in order. The first eight match the tracker CSV; the rest are read-only context. */
    public const COLUMNS = ['Directory', 'Status', 'Live URL', 'Username/Email', 'Password', 'Date Submitted', 'Verification Method', 'Notes', 'Current URL (scan)', 'Found as', 'Create at', 'Value', 'Directory site', 'Last checked'];

    public const RULES = [
        'Work only from the directories listed on each office tab. No aggregators, data feeds or automation.',
        'Copy the NAP character for character from the office tab (or the All offices NAP tab). Never retype it. If a directory forces its own format (Suite → Ste, phone format), note exactly what changed in Notes.',
        'Use the office\'s dedicated email (Verification email) for every login and put the login on the sheet. Nothing under your own accounts.',
        'Status "Missing" = create the listing. "Live — NAP mismatch, fix" = the listing exists (Current URL) and must be corrected to the NAP block. "Live" = leave it. "Needs review" = a listing was found but may not be this office\'s — check before creating another.',
        'Phone or postcard verification: submit, write the method in Verification Method, flag it in Notes and leave the verification to us.',
        'A directory that charges a fee: skip it and write "paid" in Notes unless we approved the fee in writing.',
        'Fill Live URL, Username/Email, Password, Date Submitted and Verification Method the moment a listing is submitted. Our rescan is the acceptance test.',
    ];

    public function __construct(private readonly CitationSheetExport $export) {}

    /** Build the workbook for one site (or one office of it) into an .xlsx file; returns the path. */
    public function build(Site $site, ?string $locationId = null, ?string $path = null): string
    {
        $data = $this->export->forSite($site, $locationId);
        $assets = $this->assets($site);
        $path ??= tempnam(sys_get_temp_dir(), 'citations-').'.xlsx';

        $writer = new Writer;
        $writer->openToFile($path);

        // ── Read me ─────────────────────────────────────────────────────────────────────────────────────
        $writer->getCurrentSheet()->setName('Read me');
        $writer->addRow(Row::fromValues([$data['site']['brand'].' — citation work order']));
        $writer->addRow(Row::fromValues(['Built '.now()->toDateString().' from the Citations board. One tab per office. The first eight columns of each office table are the tracker; the rest are context from our scan.']));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Ground rules']));
        foreach (self::RULES as $i => $rule) {
            $writer->addRow(Row::fromValues([($i + 1).'. '.$rule]));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Offices', 'Tab', 'Directories listed', 'To create (Missing)', 'To fix (NAP mismatch)', 'Live', 'Needs review', 'Verification email']));
        $tabs = $this->tabNames($data['locations']);
        foreach ($data['locations'] as $i => $location) {
            $counts = self::counts($location['directories']);
            $writer->addRow(Row::fromValues([
                $location['name'], $tabs[$i], count($location['directories']), $counts['missing'], $counts['mismatch'], $counts['live'], $counts['needs_review'], $location['nap']['verification_email'],
            ]));
        }

        // ── All offices NAP ──────────────────────────────────────────────────────────────────────────────
        $writer->addNewSheetAndMakeItCurrent()->setName('All offices NAP');
        $writer->addRow(Row::fromValues(['Office', 'Business / Location Name', 'Street Address', 'Suite/Unit', 'City', 'State', 'ZIP Code', 'Phone Number', 'Website URL', 'Business Categories', 'Hours of Operation', 'Short Bio/Description', 'Long Description', 'Verification email', 'Logo URL', 'Photo URLs', 'Has NAP profile']));
        foreach ($data['locations'] as $location) {
            $n = $location['nap'];
            $extra = $assets['locations'][$location['id']] ?? ['long' => '', 'logo' => '', 'photos' => []];
            $writer->addRow(Row::fromValues([
                $location['name'], $n['business_name'], $n['street'], $n['suite'], $n['city'], $n['state'], $n['zip'], $n['phone'], $n['website'], $n['categories'], $n['hours'], $n['description'],
                $extra['long'], $n['verification_email'], $extra['logo'] !== '' ? $extra['logo'] : $assets['logo'], implode(' ', $extra['photos']), $location['has_profile'] ? 'yes' : 'NO — fill the profile before this office is worked',
            ]));
        }

        // ── One tab per office ───────────────────────────────────────────────────────────────────────────
        foreach ($data['locations'] as $i => $location) {
            $writer->addNewSheetAndMakeItCurrent()->setName($tabs[$i]);
            $n = $location['nap'];
            $extra = $assets['locations'][$location['id']] ?? ['long' => '', 'logo' => '', 'photos' => []];
            $writer->addRow(Row::fromValues(['Master NAP Information', $location['name']]));
            foreach ([
                'Business / Location Name' => $n['business_name'], 'Street Address' => $n['street'], 'Suite/Unit' => $n['suite'], 'City' => $n['city'],
                'State' => $n['state'], 'ZIP Code' => $n['zip'], 'Phone Number' => $n['phone'], 'Website URL' => $n['website'],
                'Business Categories' => $n['categories'], 'Hours of Operation' => $n['hours'], 'Short Bio/Description' => $n['description'],
                'Long Description' => $extra['long'], 'Verification email' => $n['verification_email'],
                'Logo URL' => $extra['logo'] !== '' ? $extra['logo'] : $assets['logo'], 'Photo URLs' => implode(' ', $extra['photos']),
            ] as $label => $value) {
                $writer->addRow(Row::fromValues([$label, (string) $value]));
            }
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues(self::COLUMNS));
            foreach ($location['directories'] as $d) {
                $writer->addRow(Row::fromValues([
                    $d['directory'], $d['status'], (string) ($d['live_url'] ?? ''), '', '', '', '', $d['notes'],
                    (string) ($d['current_url'] ?? ''), (string) ($d['found_as'] ?? ''), (string) ($d['create_url'] ?? ''),
                    ucfirst((string) $d['tier']).($d['local'] ? ', local' : ', national'), (string) ($d['homepage'] ?? ''), (string) ($d['last_checked'] ?? ''),
                ]));
            }
        }
        $writer->close();

        return $path;
    }

    public static function filename(Site $site): string
    {
        return 'citations-'.Str::slug((string) $site->brand_name).'-'.now()->toDateString().'.xlsx';
    }

    /**
     * Excel tab names: ≤ 31 chars, no []:*?/\ , unique. The office town when it is distinctive, else the name.
     *
     * @param  list<array<string, mixed>>  $locations
     * @return list<string>
     */
    public function tabNames(array $locations): array
    {
        $out = [];
        $seen = [];
        foreach ($locations as $location) {
            $name = trim((string) preg_replace('/[\[\]:*?\/\\\\]+/', ' ', (string) $location['name']));
            $name = trim((string) preg_replace('/\s+/', ' ', $name));
            $name = $name === '' ? 'Office' : mb_substr($name, 0, 31);
            $base = $name;
            $k = 2;
            while (isset($seen[mb_strtolower($name)])) {
                $suffix = ' ('.$k++.')';
                $name = mb_substr($base, 0, 31 - mb_strlen($suffix)).$suffix;
            }
            $seen[mb_strtolower($name)] = true;
            $out[] = $name;
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $directories  @return array{missing: int, mismatch: int, live: int, needs_review: int} */
    public static function counts(array $directories): array
    {
        $c = ['missing' => 0, 'mismatch' => 0, 'live' => 0, 'needs_review' => 0];
        foreach ($directories as $d) {
            $key = (string) ($d['status_key'] ?? '');
            if (isset($c[$key])) {
                $c[$key]++;
            }
        }

        return $c;
    }

    /**
     * The brand logo and each office's long description / own logo / photos.
     *
     * @return array{logo: string, locations: array<string, array{long: string, logo: string, photos: list<string>}>}
     */
    private function assets(Site $site): array
    {
        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
        $set = is_array($branding?->logo_set) ? $branding->logo_set : [];
        $logo = trim((string) ($set['url'] ?? ''));

        $locations = [];
        LocationNapProfile::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()
            ->each(function (LocationNapProfile $p) use (&$locations): void {
                $locations[(string) $p->location_id] = [
                    'long' => trim((string) $p->description_long),
                    'logo' => trim((string) $p->logo_url),
                    'photos' => array_values(array_filter(array_map(fn (mixed $u): string => trim((string) $u), is_array($p->photo_urls) ? $p->photo_urls : []), fn (string $u): bool => $u !== '')),
                ];
            });

        return ['logo' => $logo, 'locations' => $locations];
    }
}
