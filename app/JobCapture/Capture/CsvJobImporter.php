<?php

namespace App\JobCapture\Capture;

use App\Models\Site;
use Illuminate\Support\Carbon;
use League\Csv\Reader;
use Throwable;

/**
 * Bulk-imports previous jobs from an operator's CSV — the text-only backfill path. Each row runs through the
 * same {@see ManualJobIntake} as a single add (geocode the address, create a captured job, dispatch geography
 * + enhancement), so imported rows land in the review queue as previews the operator then edits / photographs
 * / approves. Tolerant: normalizes headers, skips a row with a missing client/address or an address that can't
 * be located, and reports every skip with its row number rather than failing the whole file.
 *
 * Expected columns (header row, case/space-insensitive): client_name, address, performed_at (optional,
 * any parseable date), service_types (optional, ; or | separated), description (optional).
 */
final class CsvJobImporter
{
    /** Guard a single request against an unbounded file — larger imports should be split. */
    public const MAX_ROWS = 200;

    public function __construct(private readonly ManualJobIntake $intake) {}

    /**
     * @return array{imported: int, skipped: list<array{row: int, reason: string}>, truncated: bool}
     */
    public function import(Site $site, string $csv): array
    {
        $reader = Reader::createFromString($csv);
        $reader->setHeaderOffset(0);
        $header = array_map($this->normalizeHeader(...), $reader->getHeader());

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

            $client = trim((string) ($record['client_name'] ?? ''));
            $address = trim((string) ($record['address'] ?? ''));
            if ($client === '' || $address === '') {
                $skipped[] = ['row' => $row, 'reason' => 'missing client name or address'];

                continue;
            }

            try {
                $this->intake->intake($site, new ManualJobData(
                    clientName: $client,
                    address: $address,
                    performedAt: $this->normalizeDate((string) ($record['performed_at'] ?? '')),
                    rawDescription: trim((string) ($record['description'] ?? '')) !== '' ? trim((string) $record['description']) : null,
                    jobTypes: $this->parseTypes((string) ($record['service_types'] ?? '')),
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

    private function normalizeHeader(string $header): string
    {
        return str_replace([' ', '-'], '_', strtolower(trim($header)));
    }

    /** @return list<array{label: string}> */
    private function parseTypes(string $value): array
    {
        return collect(preg_split('/[;|]/', $value) ?: [])
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
