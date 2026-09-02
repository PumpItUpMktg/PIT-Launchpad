<?php

use App\Enums\ReviewSource;
use App\Enums\ReviewStatus;
use App\Enums\UserRole;
use App\Filament\Pages\ReviewImportPage;
use App\Jobs\ImportReviews;
use App\Models\Location;
use App\Models\Review;
use App\Models\ReviewImport;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Reviews\Import\ReviewImporter;
use App\Reviews\Import\ReviewImportReader;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Http;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

function reviewCsv(): string
{
    return "rating,body,reviewed_at,name,city,service,import_source\n"
        .'5,"Great sump pump work, very clean and professional",2026-06-01,John Smith,Trooper,Sump Pumps,google'."\n"
        .'4,"Good service overall and right on time",2026-05-15,Jane Doe,Nowhere,,google'."\n";
}

/** @return array<string, string> */
function defaultMapping(): array
{
    return array_combine(ReviewImporter::FIELDS, ReviewImporter::FIELDS);
}

test('a CSV import creates imported+pending reviews, preserves dates, resolves location, flags needs_location', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $trooperLoc = Location::factory()->for($site)->create(['served_towns' => [['name' => 'Trooper', 'state' => 'PA']]]);
    Location::factory()->for($site)->create(['served_towns' => [['name' => 'Elsewhere', 'state' => 'PA']]]); // 2nd loc → no single-location fallback
    Service::factory()->for($site)->create(['name' => 'Sump Pumps']);
    $import = ReviewImport::factory()->for($site)->create(['import_source' => 'google']);

    $path = tempnam(sys_get_temp_dir(), 'rev').'.csv';
    file_put_contents($path, reviewCsv());

    (new ImportReviews((string) $import->id, 'csv', $path, defaultMapping()))
        ->handle(app(ReviewImportReader::class), app(ReviewImporter::class));

    $reviews = Review::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
    expect($reviews)->toHaveCount(2);

    $trooper = $reviews->firstWhere('rating', 5);
    expect($trooper->source)->toBe(ReviewSource::Imported)
        ->and($trooper->status)->toBe(ReviewStatus::Pending)
        ->and($trooper->reviewed_at->toDateString())->toBe('2026-06-01') // ORIGINAL date, not import date
        ->and($trooper->location_id)->toBe((string) $trooperLoc->id)
        ->and($trooper->customer_name)->toBe('John S.')          // "First L." privacy
        ->and($trooper->import_source)->toBe('google')
        ->and($trooper->needs_location)->toBeFalse()
        ->and($trooper->services()->pluck('services.name')->all())->toBe(['Sump Pumps']);

    $nowhere = $reviews->firstWhere('rating', 4);
    expect($nowhere->needs_location)->toBeTrue()->and($nowhere->location_id)->toBeNull(); // unmatched city, row NOT failed

    expect($import->fresh()->status)->toBe('complete')
        ->and($import->fresh()->imported_count)->toBe(2);
});

test('the import dedupes against existing reviews and reports the skipped rows', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    Location::factory()->for($site)->create(['served_towns' => [['name' => 'Trooper']]]);
    Location::factory()->for($site)->create();
    Review::factory()->for($site)->create(['rating' => 5, 'reviewed_at' => '2026-06-01', 'body' => 'Great sump pump work, very clean and professional']);

    $import = ReviewImport::factory()->for($site)->create();
    app(ReviewImporter::class)->import($site, app(ReviewImportReader::class)->csv(reviewCsv()), defaultMapping(), 'google', $import);

    $import->refresh();
    expect($import->imported_count)->toBe(1)
        ->and($import->skipped_count)->toBe(1)
        ->and($import->skipped_rows[0]['reason'])->toBe('duplicate');
});

test('an XLSX file is read into rows', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'rev').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['rating', 'body', 'reviewed_at', 'city']));
    $writer->addRow(Row::fromValues(['5', 'Excellent work done quickly and well', '2026-04-01', 'Trooper']));
    $writer->close();

    $rows = app(ReviewImportReader::class)->xlsx($path);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['rating'])->toBe('5')
        ->and($rows[0]['city'])->toBe('Trooper');
});

test('a Google Sheet URL is converted to its CSV export and fetched', function (): void {
    Http::fake(['docs.google.com/*' => Http::response(reviewCsv(), 200)]);
    $reader = app(ReviewImportReader::class);

    expect($reader->toCsvExportUrl('https://docs.google.com/spreadsheets/d/ABC123/edit#gid=0'))
        ->toBe('https://docs.google.com/spreadsheets/d/ABC123/export?format=csv&gid=0');

    expect($reader->googleSheet('https://docs.google.com/spreadsheets/d/ABC123/edit'))->toHaveCount(2);
});

test('the import page is operator-gated', function (): void {
    expect(ReviewImportPage::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    expect(ReviewImportPage::canAccess())->toBeTrue();

    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(ReviewImportPage::canAccess())->toBeFalse();
});
