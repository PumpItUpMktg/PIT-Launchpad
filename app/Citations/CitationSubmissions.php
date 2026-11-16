<?php

namespace App\Citations;

use App\Enums\CitationLifecycleState;
use App\Enums\CitationPresence;
use App\Enums\CitationSource;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Collection;
use League\Csv\Reader as CsvReader;

/**
 * How a submission gets recorded (§ Citations): the operator's "Mark submitted" on a workspace row, or the
 * tracker sheet a VA filled coming back. Either way the citation's lifecycle flips to `submitted` so the next
 * scan can verify it (or stall it), the board's "Submitted" count means something, and the row leaves the
 * work order instead of being issued again. Submission itself stays manual — nothing here touches a
 * directory.
 *
 * The tracker import reads the same CSV {@see CitationSheetExport} writes: the NAP block, a blank line, then
 * the directory table. A row counts as submitted when its "Date Submitted" or "Live URL" is filled; a row
 * whose Status says rejected records the rejection with the notes as the reason. Idempotent — a directory
 * already submitted or verified is left alone and reported as such.
 */
final class CitationSubmissions
{
    public function __construct(private readonly CitationLifecycle $lifecycle = new CitationLifecycle) {}

    /**
     * Record that this office's listing on a directory was submitted (created or corrected by hand). Creates
     * the status row for a directory the scan has not reached yet, so the work is tracked from day one.
     */
    public function markSubmitted(Location $location, string $directoryId, ?string $liveUrl = null, CitationSource $source = CitationSource::Manual): CitationStatus
    {
        $status = CitationStatus::query()->withoutGlobalScope(SiteScope::class)->firstOrCreate(
            ['location_id' => $location->id, 'directory_id' => $directoryId],
            ['site_id' => $location->site_id, 'presence' => CitationPresence::Unknown, 'lifecycle' => CitationLifecycleState::None, 'source' => $source],
        );
        $liveUrl = self::url($liveUrl);
        if ($liveUrl !== null && trim((string) $status->found_url) === '') {
            $status->forceFill(['found_url' => $liveUrl])->save();   // where the VA says it is, until the scan confirms
        }
        $this->lifecycle->submit($status, $source, $liveUrl);

        return $status->refresh();
    }

    /**
     * Apply a filled tracker sheet to an office.
     *
     * @return array{submitted: int, rejected: int, already: int, unknown: list<string>, skipped: int}
     */
    public function importTracker(Location $location, string $csv): array
    {
        $out = ['submitted' => 0, 'rejected' => 0, 'already' => 0, 'unknown' => [], 'skipped' => 0];
        $directories = Directory::query()->where('is_active', true)->get();
        $statuses = CitationStatus::query()->withoutGlobalScope(SiteScope::class)
            ->where('location_id', $location->id)->get()->keyBy('directory_id');

        foreach (self::rows($csv) as $row) {
            $name = trim((string) ($row['Directory'] ?? ''));
            if ($name === '') {
                continue;
            }
            $directory = self::match($name, $directories);
            if ($directory === null) {
                $out['unknown'][] = $name;

                continue;
            }
            $statusText = mb_strtolower(trim((string) ($row['Status'] ?? '')));
            $liveUrl = self::url($row['Live URL'] ?? null);
            $submittedOn = trim((string) ($row['Date Submitted'] ?? ''));
            $notes = trim((string) ($row['Notes'] ?? ''));
            /** @var CitationStatus|null $existing */
            $existing = $statuses->get($directory->id);

            if (str_contains($statusText, 'reject')) {
                if ($existing === null) {
                    $existing = $this->markSubmitted($location, $directory->id, $liveUrl, CitationSource::Va);
                }
                if ($existing->lifecycle !== CitationLifecycleState::Rejected) {
                    $this->lifecycle->reject($existing, $notes !== '' ? $notes : null);
                    $out['rejected']++;
                } else {
                    $out['already']++;
                }

                continue;
            }
            if ($submittedOn === '' && $liveUrl === null) {
                $out['skipped']++;   // untouched row — nothing was submitted

                continue;
            }
            if ($existing !== null && in_array($existing->lifecycle, [CitationLifecycleState::Submitted, CitationLifecycleState::Verified], true)) {
                $out['already']++;

                continue;
            }
            $this->markSubmitted($location, $directory->id, $liveUrl, CitationSource::Va);
            $out['submitted']++;
        }

        return $out;
    }

    /**
     * The directory table of a tracker CSV as header-keyed rows. The table starts at the header row the export
     * writes; anything above it (the NAP block) is ignored, so the sheet can come back exactly as it went out.
     *
     * @return list<array<string, string>>
     */
    private static function rows(string $csv): array
    {
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }
        $headers = null;
        $rows = [];
        foreach (CsvReader::createFromString($csv)->getRecords() as $record) {
            $cells = array_map(fn ($v): string => trim((string) $v), array_values($record));
            if ($headers === null) {
                if (mb_strtolower($cells[0] ?? '') === 'directory') {
                    $headers = $cells;
                }

                continue;
            }
            $row = [];
            foreach ($headers as $i => $h) {
                if ($h !== '') {
                    $row[$h] = $cells[$i] ?? '';
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** A directory by its name (case-insensitive) or its domain. @param Collection<int, Directory> $directories */
    private static function match(string $name, Collection $directories): ?Directory
    {
        $needle = mb_strtolower(trim($name));
        $domain = preg_replace('#^www\.#', '', $needle) ?? $needle;

        return $directories->first(fn (Directory $d): bool => mb_strtolower((string) $d->name) === $needle
            || (preg_replace('#^www\.#', '', mb_strtolower((string) $d->domain)) ?? '') === $domain);
    }

    /** Only an http(s) URL is a listing URL; anything else is treated as blank. */
    private static function url(mixed $value): ?string
    {
        $v = is_string($value) ? trim($value) : '';

        return $v !== '' && preg_match('#^https?://#i', $v) === 1 ? $v : null;
    }
}
