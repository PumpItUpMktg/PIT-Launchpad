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
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

afterEach(fn () => CurrentSite::clear());

function reviewCsv(): string
{
    return "rating,body,reviewed_at,project_date,name,city,service,import_source\n"
        .'5,"Great sump pump work, very clean and professional",2026-06-01,2026-05-20,John Smith,Trooper,Sump Pumps,google'."\n"
        .'4,"Good service overall and right on time",2026-05-15,,Jane Doe,Nowhere,,google'."\n";
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
        ->and($trooper->project_date?->toDateString())->toBe('2026-05-20') // the day the work was done
        ->and($trooper->location_id)->toBe((string) $trooperLoc->id)
        ->and($trooper->customer_name)->toBe('John S.')          // "First L." privacy
        ->and($trooper->import_source)->toBe('google')
        ->and($trooper->needs_location)->toBeFalse()
        ->and($trooper->services()->pluck('services.name')->all())->toBe(['Sump Pumps']);

    $nowhere = $reviews->firstWhere('rating', 4);
    expect($nowhere->needs_location)->toBeTrue()->and($nowhere->location_id)->toBeNull() // unmatched city, row NOT failed
        ->and($nowhere->project_date)->toBeNull();                                      // blank project date is fine

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

test('an uploaded file travels on the import record — the worker never needs the web node\'s disk', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    Location::factory()->for($site)->create(['served_towns' => [['name' => 'Trooper']]]);
    $import = ReviewImport::factory()->for($site)->create(['source_contents' => reviewCsv()]);

    (new ImportReviews((string) $import->id, 'csv', null, defaultMapping()))
        ->handle(app(ReviewImportReader::class), app(ReviewImporter::class));

    expect($import->fresh()->status)->toBe('complete')
        ->and($import->fresh()->imported_count)->toBe(2);
});

test('an XLSX carried as base64 on the record is parsed on the worker', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    Location::factory()->for($site)->create(['served_towns' => [['name' => 'Trooper']]]);

    $path = tempnam(sys_get_temp_dir(), 'rev').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Stars', 'Review', 'Review Date', 'Project Date', 'City']));
    $writer->addRow(Row::fromValues(['5', 'Excellent work done quickly and well', '2026-04-01', '2026-03-28', 'Trooper']));
    $writer->close();
    $import = ReviewImport::factory()->for($site)->create(['source' => 'xlsx', 'source_contents' => base64_encode((string) file_get_contents($path))]);

    $mapping = ReviewImporter::guessMapping(['Stars', 'Review', 'Review Date', 'Project Date', 'City']);
    (new ImportReviews((string) $import->id, 'xlsx', null, $mapping))
        ->handle(app(ReviewImportReader::class), app(ReviewImporter::class));

    $review = Review::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
    expect($import->fresh()->status)->toBe('complete')
        ->and($review?->project_date?->toDateString())->toBe('2026-03-28')
        ->and($review?->reviewed_at->toDateString())->toBe('2026-04-01');
});

test('the CSV reader tolerates a BOM, blank trailing columns, repeated headers, and blank lines', function (): void {
    $csv = "\xEF\xBB\xBFrating,body,date,,name,name\n"
        ."5,Great work all round,2026-06-01,,John,Smith\n"
        .",,,,,\n"
        ."4,Solid job well done,2026-05-15,x,Jane,Doe\n";

    $rows = app(ReviewImportReader::class)->csv($csv);

    expect($rows)->toHaveCount(2)
        ->and(array_keys($rows[0]))->toBe(['rating', 'body', 'date', 'name', 'name (2)'])
        ->and($rows[0]['rating'])->toBe('5')
        ->and($rows[1]['name (2)'])->toBe('Doe');
});

test('column headers are auto-mapped from common spellings, each column at most once', function (): void {
    $mapping = ReviewImporter::guessMapping(['Star Rating', 'Review Text', 'Date', 'Date of Project', 'Customer Name', 'Town', 'ST', 'Zip Code', 'Service Type', 'Platform']);

    expect($mapping)->toBe([
        'rating' => 'Star Rating',
        'body' => 'Review Text',
        'reviewed_at' => 'Date',
        'project_date' => 'Date of Project',
        'name' => 'Customer Name',
        'city' => 'Town',
        'state' => 'ST',
        'zip' => 'Zip Code',
        'service' => 'Service Type',
        'import_source' => 'Platform',
    ]);

    // Exact field names still win, and a header that matches nothing is left unmapped for the operator.
    expect(ReviewImporter::guessMapping(['reviewed_at', 'body', 'rating', 'mystery']))
        ->toBe(['rating' => 'rating', 'body' => 'body', 'reviewed_at' => 'reviewed_at']);
});

test('the import page detects columns from an upload, guesses the mapping, and queues the import with the bytes on the record', function (): void {
    Queue::fake();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create();
    CurrentSite::set($site->id);

    $component = Livewire::test(ReviewImportPage::class)
        ->set('upload', UploadedFile::fake()->createWithContent('reviews.csv', reviewCsv()))
        ->assertSet('uploadedName', 'reviews.csv')
        ->call('detect')
        ->assertSet('mapping.rating', 'rating')
        ->assertSet('mapping.reviewed_at', 'reviewed_at')
        ->assertSet('mapping.project_date', 'project_date')
        ->assertSee('Great sump pump work')
        ->set('importSource', 'google')
        ->call('import')
        ->assertSet('columns', []);

    $import = ReviewImport::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
    expect($import)->not->toBeNull()
        ->and($import->source)->toBe('csv')
        ->and($import->filename)->toBe('reviews.csv')
        ->and($import->import_source)->toBe('google')
        ->and($import->source_contents)->toBe(reviewCsv());
    Queue::assertPushed(ImportReviews::class, fn (ImportReviews $job): bool => $job->importId === (string) $import->id && $job->location === null && $job->sourceType === 'csv');

    $component->assertSee('reviews.csv'); // listed under Recent imports
});

test('the import page names a failed import\'s reason instead of a bare status', function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    ReviewImport::factory()->for($site)->create(['status' => 'failed', 'error' => 'Could not fetch the Sheet (403)']);

    Livewire::test(ReviewImportPage::class)->assertSee('Could not fetch the Sheet (403)');
});
