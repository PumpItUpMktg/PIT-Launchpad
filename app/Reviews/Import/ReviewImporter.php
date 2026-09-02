<?php

namespace App\Reviews\Import;

use App\Enums\ReviewSource;
use App\Enums\ReviewStatus;
use App\Models\Review;
use App\Models\ReviewImport;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Reviews\Resolution\ReviewLocationResolver;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Turns normalized import rows into `imported`, `pending` reviews (Review Capture §10). Each review keeps its
 * ORIGINAL date (never the import date), is tagged to its Location via served-town resolution (unmatched →
 * needs_location, the row is NOT failed), and goes through the same operator approval queue as first-party
 * reviews. Deduped on (site_id, rating, reviewed_at, first 120 chars of body) against both the existing reviews
 * and the batch — a duplicate is skipped and reported, never silently merged.
 */
final class ReviewImporter
{
    /** The importable fields; rating/body/reviewed_at are required. */
    public const FIELDS = ['rating', 'body', 'reviewed_at', 'name', 'city', 'service', 'import_source'];

    public function __construct(private readonly ReviewLocationResolver $locations) {}

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<string, string>  $mapping  field => source column header
     */
    public function import(Site $site, array $rows, array $mapping, ?string $defaultImportSource, ReviewImport $progress): void
    {
        $seen = $this->existingKeys($site);
        $imported = 0;
        $skipped = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;

            $rating = (int) $this->value($row, $mapping, 'rating');
            $body = trim($this->value($row, $mapping, 'body'));
            $date = $this->parseDate($this->value($row, $mapping, 'reviewed_at'));

            if ($rating < 1 || $rating > 5 || $body === '' || $date === null) {
                $skipped[] = ['row' => $rowNumber, 'reason' => 'missing/invalid rating, body, or date'];

                continue;
            }

            $key = $this->dedupeKey($rating, $date, $body);
            if (isset($seen[$key])) {
                $skipped[] = ['row' => $rowNumber, 'reason' => 'duplicate'];

                continue;
            }
            $seen[$key] = true;

            $city = trim($this->value($row, $mapping, 'city'));
            $locationId = $city !== '' ? $this->locations->resolve($site, $city) : null;

            $review = new Review([
                'site_id' => $site->id,
                'location_id' => $locationId,
                'source' => ReviewSource::Imported,
                'import_source' => $this->value($row, $mapping, 'import_source') ?: $defaultImportSource,
                'status' => ReviewStatus::Pending,
                'rating' => $rating,
                'body' => $body,
                'customer_name' => $this->displayName($this->value($row, $mapping, 'name')),
                'reviewed_at' => $date,
                'submitted_at' => now(),
                'needs_location' => $locationId === null,
            ]);
            $review->save();

            $this->attachService($site, $review, trim($this->value($row, $mapping, 'service')));
            $imported++;
        }

        $progress->forceFill([
            'status' => 'complete',
            'total_rows' => count($rows),
            'imported_count' => $imported,
            'skipped_count' => count($skipped),
            'skipped_rows' => $skipped === [] ? null : $skipped,
        ])->save();
    }

    /** @return array<string, true> existing (rating|date|body-prefix) keys for the site */
    private function existingKeys(Site $site): array
    {
        $keys = [];
        Review::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->select(['rating', 'reviewed_at', 'body'])->get()
            ->each(function (Review $review) use (&$keys): void {
                $keys[$this->dedupeKey((int) $review->rating, $review->reviewed_at, (string) $review->body)] = true;
            });

        return $keys;
    }

    private function dedupeKey(int $rating, Carbon $date, string $body): string
    {
        return $rating.'|'.$date->toDateString().'|'.mb_substr($body, 0, 120);
    }

    /** @param array<string, string> $mapping */
    private function value(array $row, array $mapping, string $field): string
    {
        $column = $mapping[$field] ?? null;

        return $column !== null ? trim((string) ($row[$column] ?? '')) : '';
    }

    private function parseDate(string $raw): ?Carbon
    {
        if ($raw === '') {
            return null;
        }
        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /** "First L." display form (privacy §4); blank → Anonymous. */
    private function displayName(string $full): string
    {
        $full = trim($full);
        if ($full === '') {
            return 'Anonymous';
        }
        $parts = preg_split('/\s+/', $full) ?: [$full];
        if (count($parts) === 1) {
            return $parts[0];
        }

        return $parts[0].' '.mb_strtoupper(mb_substr((string) end($parts), 0, 1)).'.';
    }

    private function attachService(Site $site, Review $review, string $serviceName): void
    {
        if ($serviceName === '') {
            return;
        }
        $service = Service::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($serviceName)])->first();
        if ($service !== null) {
            $review->services()->attach($service->id);
        }
    }
}
