<?php

use App\Enums\JobStatus;
use App\JobCapture\Capture\CsvJobImporter;
use App\JobCapture\Enhancement\JobEnhancementException;
use App\JobCapture\Enhancement\JobEnhancer;
use App\JobCapture\Review\JobReviewActions;
use App\Jobs\EnhanceJob;
use App\Jobs\PublishJob;
use App\Jobs\ResolveJobGeography;
use App\Models\Job;
use App\Models\JobCity;
use App\Models\JobCounty;
use App\Models\JobType;
use App\Models\Service;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakeClaudeClient;

/** A previous job with no notes: a service, a town, a date — nothing else. */
function factsJob(Site $site, array $attrs = [], string $label = 'Sump Pump Maintenance', ?string $cityName = 'Collegeville'): Job
{
    $county = JobCounty::factory()->create(['name' => 'Montgomery', 'state' => 'PA']);
    $city = $cityName !== null ? JobCity::factory()->create(['name' => $cityName, 'state' => 'PA', 'job_county_id' => $county->id]) : null;
    $job = Job::factory()->create(array_merge([
        'site_id' => $site->id, 'status' => JobStatus::Captured,
        'raw_description' => null, 'source_description' => null, 'enhanced_description' => null,
        'performed_at' => '2020-11-02', 'photos' => [],
        'job_city_id' => $city?->id, 'job_county_id' => $county->id,
    ], $attrs));
    $job->jobTypes()->create(['label' => $label, 'slug' => Str::slug($label)]);

    return $job;
}

function factsJson(string $description, string $title = 'Sump Pump Maintenance in Collegeville, PA'): string
{
    return (string) json_encode(['title' => $title, 'meta' => 'What a sump pump maintenance visit covers for a Collegeville home.', 'description' => $description, 'alts' => []]);
}

it('imports the CRM export as it comes: name in two columns, address in four with the ZIP repaired, the service in Tags, the date in Created Date, J-ID as the import key — and a re-import skips rows already in', function () {
    Queue::fake();
    Bus::fake();
    bindTolerantGeocoder();
    $site = Site::factory()->create();

    $tsv = "J-ID\tCreated Date\tFirst Name\tLast Name\tMain Phone\tText Message Enabled Phone #\tAlt Phone\tE-mails\tTags\tService Street 1\tService City\tService State\tService ZIP code\t\t\n"
        ."33955641_36646236\t2020-11-02\tMatt\tKeeley\t(610) 322-2316\t(610) 322-2316\t\t\tSump Pump Installation\t933 Mount Airy Road\tCollegeville\tPA\t19426\t\t\n"
        ."33954126_36644399\t2020-11-02\tTerry\tCollins\t\t(646) 533-1212\t\t\tYard Drainage\t49 Sleepy Hollow Road\tRed Bank\tNJ\t7701\t\t\n"
        ."33953924_36644160\t2020-11-02\tPhil\tGrossman\t(908) 614-7971\t(908) 614-7971\t\tpsg980@gmail.com\tSump Pump Replacement\t150 Santiago Avenue\tRutherford\tNJ\t7070\t\t\n";

    $result = app(CsvJobImporter::class)->import($site, $tsv);
    expect($result['imported'])->toBe(3)->and($result['skipped'])->toBe([]);

    $terry = Job::withoutGlobalScopes()->where('import_key', '33954126_36644399')->sole();
    expect($terry->client_name_full)->toBe('Terry Collins')
        ->and($terry->client_name_display)->toBe('Terry C.')
        ->and($terry->address_true)->toBe('49 Sleepy Hollow Road, Red Bank, NJ 07701')   // the leading zero is back
        ->and($terry->performed_at->toDateString())->toBe('2020-11-02')
        ->and($terry->raw_description)->toBeNull()
        ->and($terry->jobTypes()->pluck('label')->all())->toBe(['Yard Drainage']);
    // Phones and emails never land anywhere.
    expect(json_encode($terry->getAttributes()))->not->toContain('646')->not->toContain('gmail');
    // No notes + a service → geography first, then the facts-only write-up, in order.
    Bus::assertChained([ResolveJobGeography::class, EnhanceJob::class]);

    $again = app(CsvJobImporter::class)->import($site, $tsv);
    expect($again['imported'])->toBe(0)
        ->and($again['skipped'][0]['reason'])->toBe('already imported (33955641_36646236)')
        ->and(Job::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBe(3);
});

it('the import preview matches every tag to the catalog, suggests the closest row for a stray one, and the mapping lands the job on the catalog row — or as a new service, or off the job', function () {
    Queue::fake();
    Bus::fake();
    bindTolerantGeocoder();
    $site = Site::factory()->create();
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Installation']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'French Drain']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Basement Waterproofing']);
    $csv = 'J-ID,Created Date,First Name,Last Name,Tags,Service Street 1,Service City,Service State,Service ZIP code
'
        .'1,2020-11-02,Matt,Keeley,Sump Pump Installation,933 Mount Airy Road,Collegeville,PA,19426
'
        .'2,2020-11-02,Terry,Collins,Yard Drainage,49 Sleepy Hollow Road,Red Bank,NJ,7701
'
        .'3,2020-11-03,Phil,Grossman,Sump pump install,150 Santiago Avenue,Rutherford,NJ,7070
'
        .'4,2020-11-04,Danny,Dearing,French Drain Installation,165 New York Avenue,Jersey City,NJ,7307
';

    $preview = app(CsvJobImporter::class)->preview($site, $csv);
    $tags = collect($preview['tags'])->keyBy('tag');
    expect($preview['rows'])->toBe(4)->and($preview['already_imported'])->toBe(0)
        ->and($tags['Sump Pump Installation']['label'])->toBe('Sump Pump Installation')                        // exact match
        ->and($tags['Sump pump install']['job_type_id'])->toBeNull()
        ->and($tags['Sump pump install']['suggestions'][0]['label'])->toBe('Sump Pump Installation')             // contained → closest
        ->and($tags['French Drain Installation']['suggestions'][0]['label'])->toBe('French Drain')
        ->and($tags['Yard Drainage']['suggestions'][0]['label'])->toBe('French Drain')                            // a stem in common: offered, the operator decides
        ->and(array_column($preview['options'], 'label'))->toContain('Basement Waterproofing');

    $frenchDrain = collect($preview['options'])->firstWhere('label', 'French Drain')['id'];
    $install = collect($preview['options'])->firstWhere('label', 'Sump Pump Installation')['id'];
    $result = app(CsvJobImporter::class)->import($site, $csv, [
        'Sump pump install' => $install, 'French Drain Installation' => $frenchDrain, 'Yard Drainage' => CsvJobImporter::AS_NEW,
    ]);
    expect($result['imported'])->toBe(4);
    $byKey = fn (string $k) => Job::withoutGlobalScopes()->where('site_id', $site->id)->where('import_key', $k)->sole();
    expect($byKey('3')->jobTypes()->pluck('label')->all())->toBe(['Sump Pump Installation'])                  // mapped onto the catalog row
        ->and($byKey('3')->jobTypes()->value('job_type_id'))->toBe($install)
        ->and($byKey('4')->jobTypes()->pluck('label')->all())->toBe(['French Drain'])
        ->and($byKey('2')->jobTypes()->pluck('label')->all())->toBe(['Yard Drainage'])                           // a new service, as typed
        ->and($byKey('2')->jobTypes()->value('job_type_id'))->toBeNull();

    // SKIP leaves the service off; a re-preview counts the rows already in.
    $more = 'J-ID,First Name,Last Name,Tags,Service Street 1,Service City,Service State,Service ZIP code
5,Ann,Lee,Gutter Talk,1 Main St,Trappe,PA,19426
2,Terry,Collins,Yard Drainage,49 Sleepy Hollow Road,Red Bank,NJ,7701
';
    $again = app(CsvJobImporter::class)->preview($site, $more);
    expect($again['already_imported'])->toBe(1)
        ->and(collect($again['tags'])->firstWhere('tag', 'Gutter Talk')['suggestions'])->toBe([]);                  // nothing close at all
    $r = app(CsvJobImporter::class)->import($site, $more, ['Gutter Talk' => CsvJobImporter::SKIP]);
    expect($r['imported'])->toBe(1)->and($byKey('5')->jobTypes()->count())->toBe(0);
});

it('writes a previous job with no notes from facts only: the service, the catalog\'s account of it, the town and county, the season, one angle — under rules that forbid invented findings', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $service = Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Maintenance', 'short_description' => 'A yearly check and test of the pump, float, check valve and discharge line.',
        'scope_items' => ['Test the float switch', 'Clean the pit and intake screen', 'Check the check valve and discharge line'], 'symptoms' => ['The pump runs constantly', 'A musty basement']]);
    $vocab = JobType::factory()->create(['site_id' => $site->id, 'label' => 'Sump Pump Maintenance', 'slug' => 'sump-pump-maintenance', 'service_id' => $service->id]);
    $job = factsJob($site);
    $job->jobTypes()->update(['job_type_id' => $vocab->id]);
    $model = new FakeClaudeClient(factsJson("A sump pump maintenance visit in Collegeville covers the float switch, the check valve and the discharge line, with a full test cycle before the technician leaves.\n\nLate autumn in Montgomery County brings leaf-choked drains and the first hard rains; a pump that has sat idle all summer is worth testing before the basement needs it."));

    (new JobEnhancer($model))->enhance($job);
    $job->refresh();

    expect($job->status)->toBe(JobStatus::Review)
        ->and($job->facts_only)->toBeTrue()
        ->and($job->write_up_angle)->toBe('prevents')          // the first facts-only job on the site takes the first angle
        ->and($job->similar_to_job_id)->toBeNull()
        ->and($job->enhanced_description)->toContain('covers the float switch')
        ->and($job->post_title)->toBe('Sump Pump Maintenance in Collegeville, PA')
        ->and($job->raw_description)->toBeNull();               // nothing was written into the tech's field

    $prompt = $model->prompts[0];
    expect($prompt)->toContain('There are NO notes from the job')
        ->toContain('Service: Sump Pump Maintenance — A yearly check and test of the pump')
        ->toContain('what it includes: Test the float switch; Clean the pit')
        ->toContain('signs a home needs it: The pump runs constantly')
        ->toContain('Town served: Collegeville, Montgomery County')
        ->toContain('When: autumn 2020 (November)')
        ->toContain('Angle: what this service PREVENTS')
        ->toContain('Never invent parts, brands, model numbers, measurements, causes, prices')
        ->not->toContain("Technician's notes");

    // A second facts-only job on the site takes the next angle; a job with neither notes nor a service cannot be written at all.
    $second = factsJob($site, [], 'Sump Pump Maintenance', 'Trappe');
    (new JobEnhancer(new FakeClaudeClient(factsJson('Entirely different words about a Trappe visit in the cold months, written another way around.', 'Sump Pump Maintenance in Trappe, PA'))))->enhance($second);
    expect($second->fresh()->write_up_angle)->toBe('covers');
    $bare = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Captured, 'raw_description' => null, 'source_description' => null, 'enhanced_description' => null]);
    expect(fn () => (new JobEnhancer(new FakeClaudeClient(factsJson('x'))))->enhance($bare))->toThrow(JobEnhancementException::class, 'no description and no service');
    expect($bare->fresh()->status)->toBe(JobStatus::Captured);
});

it('a facts-only write-up that reads like an earlier one for the same service gets one retry on another angle, and is flagged — blocking approve — when still too alike; an edit clears the flag', function () {
    Queue::fake();
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $text = 'A sump pump maintenance visit covers the float switch, the check valve and the discharge line, with a full test cycle before the technician leaves the basement dry and ready.';
    $earlier = factsJob($site, ['status' => JobStatus::Review, 'enhanced_description' => $text, 'facts_only' => true, 'write_up_angle' => 'prevents']);

    // The model answers the same text twice: the retry does not help → flagged against the earlier job.
    $model = new FakeClaudeClient(factsJson($text));
    $job = factsJob($site, [], 'Sump Pump Maintenance', 'Trappe');
    (new JobEnhancer($model))->enhance($job);
    $job->refresh();
    expect(count($model->prompts))->toBe(2)
        ->and($model->prompts[1])->toContain('Use a DIFFERENT structure')
        ->and($job->status)->toBe(JobStatus::Review)
        ->and($job->similar_to_job_id)->toBe($earlier->id)
        ->and($job->write_up_angle)->toBe('season');           // moved on from the angle the first pass used

    CurrentSite::set($site->id);
    $actions = app(JobReviewActions::class);
    expect($actions->canApprove($job))->toBeFalse()->and($actions->approve($job))->toBeFalse();
    Queue::assertNotPushed(PublishJob::class);

    // The operator rewrites it in their own words → the flag lifts and it can go.
    $actions->saveEdits($job, ['enhanced_description' => 'Trappe sits on heavy clay, and a pump that has not run since spring is the one that fails in the first November storm; this visit tests it under load.']);
    $job->refresh();
    expect($job->similar_to_job_id)->toBeNull()->and($actions->canApprove($job))->toBeTrue();

    // A different write-up on the first try is simply kept, one call, no flag.
    $fresh = new FakeClaudeClient(factsJson('Homes around Royersford sit low along the Schuylkill, and spring thaw is when a pump earns its keep; this visit is about making sure it does.', 'Sump Pump Maintenance in Royersford, PA'));
    $third = factsJob($site, [], 'Sump Pump Maintenance', 'Royersford');
    (new JobEnhancer($fresh))->enhance($third);
    expect(count($fresh->prompts))->toBe(1)->and($third->fresh()->similar_to_job_id)->toBeNull();

    // A job WITH notes is untouched by any of this: not facts-only, no angle, no similarity check.
    $noted = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Captured, 'raw_description' => 'replaced pump', 'source_description' => 'Replaced a failed pump.', 'enhanced_description' => null, 'photos' => []]);
    $noted->jobTypes()->create(['label' => 'Sump Pump Maintenance', 'slug' => 'sump-pump-maintenance']);
    (new JobEnhancer(new FakeClaudeClient(factsJson($text))))->enhance($noted);
    expect($noted->fresh()->facts_only)->toBeFalse()->and($noted->fresh()->similar_to_job_id)->toBeNull();
});
