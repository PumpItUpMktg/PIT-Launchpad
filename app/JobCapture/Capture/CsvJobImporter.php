<?php

namespace App\JobCapture\Capture;

use App\JobCapture\Types\JobTypeVocabulary;
use App\Models\Job;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use League\Csv\Reader;
use Throwable;

/**
 * Bulk-imports previous jobs from an operator's CSV — the text-only backfill path. Each row runs through the
 * same {@see ManualJobIntake} as a single add (geocode the address, create a captured job, dispatch geography
 * + enhancement), so imported rows land in the review queue as previews the operator then edits / photographs
 * / approves. Tolerant: normalizes headers, skips a row with a missing client/address or an address that can't
 * be located, and reports every skip with its row number rather than failing the whole file.
 *
 * Two shapes are read. The template: client_name, address, performed_at (optional, any parseable date),
 * service_types (optional, ; or | separated), description (optional). And a CRM export as it comes: the
 * name split across First Name / Last Name, the address across Street / City / State / ZIP (a ZIP that
 * lost its leading zero is restored), the service in Tags, the date in Created Date, a job id in J-ID
 * (kept as the import key, so a re-import of the same file skips rows already in). Tab- or comma-separated.
 * Phones and emails in the export are ignored — nothing of the client's beyond "First L." reaches a page.
 */
final class CsvJobImporter
{
    public const MAX_ROWS = 500;

    /** Header aliases (after normalisation): the CRM's words → the template's. */
    private const ALIASES = [
        'client_name' => ['client_name', 'client', 'customer', 'customer_name', 'name', 'full_name', 'contact'],
        'first_name' => ['first_name', 'first', 'firstname'],
        'last_name' => ['last_name', 'last', 'lastname', 'surname'],
        'address' => ['address', 'service_address', 'job_address', 'full_address', 'location', 'site_address'],
        'street' => ['street', 'service_street', 'service_street_1', 'street_1', 'address_1', 'address_line_1', 'service_address_1'],
        'city' => ['city', 'service_city', 'town'],
        'state' => ['state', 'service_state', 'st', 'province'],
        'zip' => ['zip', 'zip_code', 'zipcode', 'service_zip', 'service_zip_code', 'postal_code', 'postal'],
        'performed_at' => ['performed_at', 'date', 'job_date', 'service_date', 'completed', 'completed_date', 'completed_at', 'created_date', 'created', 'work_date', 'performed'],
        'service_types' => ['service_types', 'service_type', 'service', 'services', 'job_type', 'job_types', 'type', 'tags', 'tag', 'category'],
        'description' => ['description', 'notes', 'job_notes', 'desc', 'details', 'work_performed', 'summary'],
        'import_key' => ['import_key', 'j_id', 'jid', 'job_id', 'id', 'crm_id', 'external_id', 'invoice', 'invoice_number'],
    ];

    /** Mapping choices for a tag the catalog does not know: import it as a new service, or leave it off the job. */
    public const AS_NEW = 'new';

    public const SKIP = 'skip';

    public function __construct(private readonly ManualJobIntake $intake, private readonly JobTypeVocabulary $vocabulary) {}

    /**
     * What an import would do before it does it: the rows, and every distinct service tag with how many rows
     * carry it, the catalog row it matches (by slug, case-insensitive), or — when it matches nothing — the
     * closest catalog rows as suggestions so the operator can map it rather than import a stray twin.
     *
     * @return array{rows: int, already_imported: int, tags: list<array{tag: string, count: int, job_type_id: string|null, label: string|null, suggestions: list<array{id: string, label: string}>}>, options: list<array{id: string, label: string, slug: string}>}
     */
    public function preview(Site $site, string $csv): array
    {
        [$reader, $header, $columns] = $this->open($csv);
        $options = $this->vocabulary->options((string) $site->id);
        $bySlug = [];
        foreach ($options as $o) {
            $bySlug[$o['slug']] = $o;
        }
        $counts = [];
        $rows = 0;
        $already = 0;
        foreach ($reader->getRecords($header) as $record) {
            $rows++;
            if ($rows > self::MAX_ROWS) {
                break;
            }
            $get = fn (string $field): string => trim((string) ($record[$columns[$field] ?? ''] ?? ''));
            $key = $get('import_key');
            if ($key !== '' && Job::withoutGlobalScopes()->where('site_id', $site->id)->where('import_key', $key)->exists()) {
                $already++;
            }
            foreach ($this->parseTypes($get('service_types')) as $t) {
                $counts[$t['label']] = ($counts[$t['label']] ?? 0) + 1;
            }
        }
        arsort($counts);
        $tags = [];
        foreach ($counts as $tag => $n) {
            $slug = Str::slug((string) $tag);
            $match = $bySlug[$slug] ?? null;
            $tags[] = [
                'tag' => (string) $tag,
                'count' => $n,
                'job_type_id' => $match['id'] ?? null,
                'label' => $match['label'] ?? null,
                'suggestions' => $match !== null ? [] : self::suggest((string) $tag, $options),
            ];
        }

        return ['rows' => min($rows, self::MAX_ROWS), 'already_imported' => $already, 'tags' => $tags, 'options' => $options];
    }

    /**
     * @param  array<string, string>  $mapping  tag → a job-type id (map onto that catalog row), AS_NEW (import the tag as its own service) or SKIP (leave it off)
     * @return array{imported: int, skipped: list<array{row: int, reason: string}>, truncated: bool}
     */
    public function import(Site $site, string $csv, array $mapping = []): array
    {
        [$reader, $header, $columns] = $this->open($csv);
        $options = $mapping !== [] ? collect($this->vocabulary->options((string) $site->id))->keyBy('id')->all() : [];

        $imported = 0;
        $skipped = [];
        $truncated = false;
        $row = 0;

        foreach ($reader->getRecords($header) as $record) {
            $row++;
            if ($row > self::MAX_ROWS) {
                $truncated = true;
                break;
            }
            $get = fn (string $field): string => trim((string) ($record[$columns[$field] ?? ''] ?? ''));

            $client = $get('client_name');
            if ($client === '') {
                $client = trim($get('first_name').' '.$get('last_name'));
            }
            $address = $get('address');
            if ($address === '') {
                $address = self::composeAddress($get('street'), $get('city'), $get('state'), $get('zip'));
            }
            if ($client === '' || $address === '') {
                $skipped[] = ['row' => $row, 'reason' => 'missing client name or address'];

                continue;
            }
            $importKey = $get('import_key');
            if ($importKey !== '' && Job::withoutGlobalScopes()->where('site_id', $site->id)->where('import_key', $importKey)->exists()) {
                $skipped[] = ['row' => $row, 'reason' => "already imported ({$importKey})"];

                continue;
            }

            try {
                $this->intake->intake($site, new ManualJobData(
                    clientName: $client,
                    address: $address,
                    performedAt: $this->normalizeDate($get('performed_at')),
                    rawDescription: $get('description') !== '' ? $get('description') : null,
                    jobTypes: $this->applyMapping($this->parseTypes($get('service_types')), $mapping, $options),
                    importKey: $importKey !== '' ? mb_substr($importKey, 0, 120) : null,
                ));
                $imported++;
            } catch (CouldNotPlaceJobException) {
                $skipped[] = ['row' => $row, 'reason' => 'address could not be located'];
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'truncated' => $truncated];
    }

    /** A header CSV template (the columns + one example row) for the operator to fill in. */
    public function template(): string
    {
        return implode("\n", [
            'client_name,address,performed_at,service_types,description',
            'Jane Homeowner,"12 Main St, Somerville NJ 08876",2025-06-01,Sump Pump Replacement;French Drain,"Replaced a failed sump pump and added a French drain along the north wall."',
        ])."\n";
    }

    /** @return array{0: Reader, 1: list<string>, 2: array<string, string>} */
    private function open(string $csv): array
    {
        $csv = ltrim($csv, "\xEF\xBB\xBF");
        $reader = Reader::createFromString($csv);
        $reader->setDelimiter(self::delimiter($csv));
        $reader->setHeaderOffset(0);
        $header = self::uniqueHeaders(array_map($this->normalizeHeader(...), $reader->getHeader()));

        return [$reader, $header, $this->mapColumns($header)];
    }

    /**
     * Apply the operator's tag mapping: a mapped tag takes the catalog row's label + slug (so the intake links
     * it there), AS_NEW keeps the tag as typed, SKIP drops it, an unmapped tag passes through unchanged.
     *
     * @param  list<array{label: string}>  $types  @param  array<string, string>  $mapping  @param  array<string, array{id: string, label: string, slug: string}>  $options
     * @return list<array{label: string, slug?: string}>
     */
    private function applyMapping(array $types, array $mapping, array $options): array
    {
        if ($mapping === []) {
            return $types;
        }
        $out = [];
        foreach ($types as $t) {
            $choice = $mapping[$t['label']] ?? null;
            if ($choice === self::SKIP) {
                continue;
            }
            if ($choice !== null && $choice !== self::AS_NEW && isset($options[$choice])) {
                $out[] = ['label' => $options[$choice]['label'], 'slug' => $options[$choice]['slug']];

                continue;
            }
            $out[] = $t;
        }

        return $out;
    }

    /**
     * The closest catalog rows to a tag the catalog does not know: one contains the other, or the words overlap,
     * or the spelling is close. Up to three, best first.
     *
     * @param  list<array{id: string, label: string, slug: string}>  $options
     * @return list<array{id: string, label: string}>
     */
    public static function suggest(string $tag, array $options): array
    {
        $a = strtolower(trim($tag));
        $aw = array_filter(explode(' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $a)));
        $scored = [];
        foreach ($options as $o) {
            $b = strtolower($o['label']);
            $bw = array_filter(explode(' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $b)));
            $score = 0.0;
            if ($a !== '' && ($a === $b || str_contains($b, $a) || str_contains($a, $b))) {
                $score = 0.9;
            } elseif ($aw !== [] && $bw !== []) {
                $overlap = count(array_intersect($aw, $bw)) / max(count($aw), count($bw));
                similar_text($a, $b, $pct);
                $score = max($overlap, $pct / 100);
            }
            if ($score >= 0.45) {
                $scored[] = ['id' => $o['id'], 'label' => $o['label'], 'score' => $score];
            }
        }
        usort($scored, fn (array $x, array $y): int => $y['score'] <=> $x['score']);

        return array_map(fn (array $s): array => ['id' => $s['id'], 'label' => $s['label']], array_slice($scored, 0, 3));
    }

    /** Tab-separated when the header line has tabs and no commas (a spreadsheet paste); else comma. */
    private static function delimiter(string $csv): string
    {
        $firstLine = strtok($csv, "\n") ?: '';

        return str_contains($firstLine, "\t") && substr_count($firstLine, "\t") >= substr_count($firstLine, ',') ? "\t" : ',';
    }

    /** @param  list<string>  $header  @return array<string, string> field → the header it maps to */
    private function mapColumns(array $header): array
    {
        $map = [];
        foreach (self::ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                if (in_array($alias, $header, true)) {
                    $map[$field] = $alias;
                    break;
                }
            }
        }

        return $map;
    }

    private static function composeAddress(string $street, string $city, string $state, string $zip): string
    {
        if ($street === '' && $city === '') {
            return '';
        }
        if ($zip !== '' && preg_match('/^\d{3,4}$/', $zip) === 1) {
            $zip = str_pad($zip, 5, '0', STR_PAD_LEFT);   // a spreadsheet dropped the leading zero (07701 → 7701)
        }
        $regionZip = trim($state.' '.$zip);
        $parts = array_values(array_filter([$street, $city, $regionZip], fn (string $p): bool => $p !== ''));

        return implode(', ', $parts);
    }

    /** Blank and repeated headers (a spreadsheet's trailing empty columns) are made unique so the reader accepts them. @param list<string> $headers @return list<string> */
    private static function uniqueHeaders(array $headers): array
    {
        $seen = [];
        $out = [];
        foreach ($headers as $i => $h) {
            $name = $h !== '' ? $h : '_blank_'.$i;
            if (isset($seen[$name])) {
                $name .= '_'.$i;
            }
            $seen[$name] = true;
            $out[] = $name;
        }

        return $out;
    }

    private function normalizeHeader(string $header): string
    {
        $h = strtolower(trim($header));
        $h = preg_replace('/[^a-z0-9]+/', '_', $h) ?? $h;

        return trim($h, '_');
    }

    /** @return list<array{label: string}> */
    private function parseTypes(string $value): array
    {
        return collect(preg_split('/[;|,]/', $value) ?: [])
            ->map(fn (string $type): string => trim($type))
            ->filter()->unique()
            ->take(3)
            ->map(fn (string $label): array => ['label' => $label])
            ->values()->all();
    }

    /** Parse a loose date into Y-m-d, or null when it's blank / unparseable (the job just has no date). */
    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
