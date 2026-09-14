<?php

namespace App\Reviews\Import;

use App\Enums\ReviewSource;
use App\Enums\ReviewStatus;
use App\Jobs\GeocodeReview;
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
    /** The importable fields; rating/body/reviewed_at are required. `project_date` is the day the work was done. */
    public const FIELDS = ['rating', 'body', 'reviewed_at', 'project_date', 'name', 'city', 'state', 'zip', 'service', 'import_source'];

    /**
     * Header spellings that auto-map to each field (compared after lower-casing and stripping non-alphanumerics,
     * so "Review Date", "review_date" and "ReviewDate" all match). First column wins; a column maps once.
     *
     * @var array<string, list<string>>
     */
    private const HEADER_ALIASES = [
        'rating' => ['rating', 'stars', 'star rating', 'score', 'review rating', 'star'],
        'body' => ['body', 'review', 'review text', 'review body', 'text', 'comment', 'comments', 'content', 'message', 'testimonial'],
        'reviewed_at' => ['reviewed at', 'reviewed', 'review date', 'date', 'date of review', 'posted', 'posted at', 'created', 'created at', 'time'],
        'project_date' => ['project date', 'date of project', 'job date', 'date of job', 'service date', 'date of service', 'work date', 'completed', 'completed at', 'completion date', 'date completed', 'performed at', 'performed'],
        'name' => ['name', 'customer', 'customer name', 'reviewer', 'reviewer name', 'author', 'client', 'client name', 'full name'],
        'city' => ['city', 'town', 'municipality'],
        'state' => ['state', 'st', 'province'],
        'zip' => ['zip', 'zip code', 'zipcode', 'postal code', 'postcode'],
        'service' => ['service', 'services', 'service type', 'job type', 'service performed'],
        'import_source' => ['import source', 'source', 'platform', 'site'],
    ];

    public function __construct(private readonly ReviewLocationResolver $locations) {}

    /**
     * Guess field => column from the sheet's headers. Only the operator-visible starting point — every mapping
     * stays editable on the page before the import is committed.
     *
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    public static function guessMapping(array $columns): array
    {
        $normalized = [];
        foreach ($columns as $column) {
            $normalized[$column] = preg_replace('/[^a-z0-9]/', '', mb_strtolower($column)) ?? '';
        }

        $mapping = [];
        foreach (self::FIELDS as $field) {
            $aliases = array_map(fn (string $a): string => str_replace(' ', '', $a), [$field, ...self::HEADER_ALIASES[$field]]);
            foreach ($normalized as $column => $key) {
                if ($key !== '' && in_array($key, $aliases, true) && ! in_array($column, $mapping, true)) {
                    $mapping[$field] = (string) $column;
                    break;
                }
            }
        }

        return $mapping;
    }

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
            $projectDate = $this->parseDate($this->value($row, $mapping, 'project_date'));

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
            $state = trim($this->value($row, $mapping, 'state'));
            // A "Town, ST" city with no separate state column → split it, so town is the bare town.
            if ($state === '' && preg_match('/^(.*),\s*([A-Za-z]{2})$/', $city, $m) === 1) {
                [$city, $state] = [trim($m[1]), mb_strtoupper($m[2])];
            }
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
                // The review's OWN geography, from the sheet — displayed and radius-filtered (never the
                // owning location's city). Lat/lng are filled off-request by GeocodeReview.
                'town' => $city !== '' ? $city : null,
                'state' => $state !== '' ? $state : null,
                'postal_code' => $this->value($row, $mapping, 'zip') ?: null,
                'reviewed_at' => $date,
                'project_date' => $projectDate?->toDateString(),
                'submitted_at' => now(),
                'needs_location' => $locationId === null,
            ]);
            $review->save();

            GeocodeReview::dispatch((string) $review->id);
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
