<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Filament\Pages\JobsBoard;
use App\Integrations\Census\Geocoder;
use App\Integrations\Census\GeocodeResult;
use App\Jobs\EnhanceJob;
use App\Jobs\PublishJob;
use App\Jobs\UnpublishJob;
use App\Models\Job;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Operator\Jobs\JobPortfolio;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

function reviewJob(Site $site): Job
{
    // A reviewed job carrying a write-up (hasDraft) — approvable.
    return Job::factory()->create([
        'site_id' => $site->id,
        'status' => JobStatus::Review,
        'enhanced_description' => 'A thorough replacement of a failed 50-gallon water heater.',
        'post_title' => 'Water heater replacement',
    ]);
}

it('is operator-only', function () {
    expect(JobsBoard::canAccess())->toBeTrue(); // operator (beforeEach)

    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(JobsBoard::canAccess())->toBeFalse();
});

it('summarizes and scopes jobs to the locked tenant', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();

    Job::factory()->count(3)->create(['site_id' => $a->id, 'status' => JobStatus::Review, 'enhanced_description' => 'x']);
    Job::factory()->count(2)->create(['site_id' => $a->id, 'status' => JobStatus::Published, 'wp_post_id' => 5]);
    Job::factory()->create(['site_id' => $a->id, 'status' => JobStatus::PublishFailed]);
    // Another tenant's jobs must not leak in.
    Job::factory()->count(4)->create(['site_id' => $b->id, 'status' => JobStatus::Review]);

    $board = app(JobPortfolio::class)->for($a->id);

    expect($board['summary']['review_backlog'])->toBe(3)
        ->and($board['summary']['published'])->toBe(2)
        ->and($board['summary']['failed'])->toBe(1)
        ->and($board['queue'])->toHaveCount(3)      // Review + Captured + Enhancing (here: 3 Review)
        ->and($board['published'])->toHaveCount(2);
});

it('orders the queue Review-first', function () {
    $site = Site::factory()->create();
    Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Captured]);
    $review = reviewJob($site);

    $queue = app(JobPortfolio::class)->for($site->id)['queue'];

    expect($queue[0]['id'])->toBe($review->id)   // Review jumps ahead of Captured
        ->and($queue[0]['has_draft'])->toBeTrue();
});

it('approves a reviewed job into the publish pipeline', function () {
    Bus::fake();
    $site = Site::factory()->create();
    $job = reviewJob($site);
    app(ActiveTenant::class)->set($site->id);

    Livewire::test(JobsBoard::class)->call('approve', $job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Approved);
    Bus::assertDispatched(PublishJob::class);
});

it('does not approve a job with no write-up', function () {
    Bus::fake();
    $site = Site::factory()->create();
    $job = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Review, 'enhanced_description' => null]);
    app(ActiveTenant::class)->set($site->id);

    Livewire::test(JobsBoard::class)->call('approve', $job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Review); // unchanged
    Bus::assertNotDispatched(PublishJob::class);
});

it('rejects, re-enhances, retries and takes down through the board', function () {
    Bus::fake();
    $site = Site::factory()->create();
    app(ActiveTenant::class)->set($site->id);

    $review = reviewJob($site);
    Livewire::test(JobsBoard::class)
        ->call('startReject', $review->id)
        ->set('rejectReason', 'Blurry photos')
        ->call('confirmReject');
    expect($review->refresh()->status)->toBe(JobStatus::Rejected)
        ->and($review->reject_reason)->toBe('Blurry photos');

    $enh = reviewJob($site);
    Livewire::test(JobsBoard::class)->call('reEnhance', $enh->id);
    Bus::assertDispatched(EnhanceJob::class);

    $failed = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::PublishFailed]);
    Livewire::test(JobsBoard::class)->call('retryPublish', $failed->id);
    Bus::assertDispatched(PublishJob::class);

    $live = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Published, 'wp_post_id' => 12]);
    Livewire::test(JobsBoard::class)->call('takeDown', $live->id);
    expect($live->refresh()->status)->toBe(JobStatus::Approved);
    Bus::assertDispatched(UnpublishJob::class);
});

it('never acts on a job outside the locked tenant', function () {
    Bus::fake();
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    $foreign = reviewJob($b);
    app(ActiveTenant::class)->set($a->id);

    Livewire::test(JobsBoard::class)->call('approve', $foreign->id);

    expect($foreign->refresh()->status)->toBe(JobStatus::Review); // untouched
    Bus::assertNotDispatched(PublishJob::class);
});

it('renders tenant-locked with jobs and no per-page site picker', function () {
    $site = Site::factory()->create();
    Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Review, 'enhanced_description' => 'x', 'post_title' => 'Sump pump swap in Trenton']);
    app(ActiveTenant::class)->set($site->id);

    $html = Livewire::test(JobsBoard::class)->assertOk()->html();

    expect($html)->toContain('Sump pump swap in Trenton')
        ->and($html)->toContain('Review queue')
        ->and($html)->not->toContain('<select'); // tenant comes from the lock
});

// ── The workbench (add / import / edit / photos) on the admin board ─────────────────────────────

function bindBoardGeocoder(): void
{
    app()->instance(Geocoder::class, new class implements Geocoder
    {
        public function geocode(string $address): ?GeocodeResult
        {
            return new GeocodeResult(40.5, -74.4, $address);
        }
    });
}

it('offers the site services as pickable job types (synced from the catalog)', function () {
    $site = Site::factory()->create();
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Replacement']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'French Drain']);
    app(ActiveTenant::class)->set($site->id);

    $html = Livewire::test(JobsBoard::class)->call('toggleAddJob')->assertOk()->html();

    expect($html)->toContain('Sump Pump Replacement')->toContain('French Drain')->toContain('Import CSV');
});

it('adds a previous job from the board with services picked from the catalog', function () {
    Bus::fake();
    bindBoardGeocoder();
    $site = Site::factory()->create();
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Replacement']);
    app(ActiveTenant::class)->set($site->id);

    Livewire::test(JobsBoard::class)
        ->call('toggleAddJob')
        ->set('newClientName', 'Jane Homeowner')
        ->set('newAddress', '12 Main St, Somerville NJ')
        ->set('newPerformedAt', '2025-05-20')
        ->set('newJobTypeLabels', ['Sump Pump Replacement'])
        ->set('newJobTypesOther', 'Gutter cleanup')
        ->set('newDescription', 'Replaced the pump.')
        ->call('addJob')
        ->assertSet('addingJob', false);

    $job = Job::withoutGlobalScopes()->where('site_id', $site->id)->first();
    expect($job)->not->toBeNull()
        ->and($job->client_name_display)->toBe('Jane H.')
        ->and($job->performed_at->toDateString())->toBe('2025-05-20')
        ->and($job->jobTypes()->orderBy('label')->pluck('label')->all())->toBe(['Gutter cleanup', 'Sump Pump Replacement'])
        ->and($job->jobTypes()->where('slug', 'sump-pump-replacement')->value('job_type_id'))->not->toBeNull();
});

it('imports a CSV from the board and matches service_types to the catalog', function () {
    Bus::fake();
    bindBoardGeocoder();
    $site = Site::factory()->create();
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Replacement']);
    app(ActiveTenant::class)->set($site->id);
    $csv = "client_name,address,performed_at,service_types,description\nJane Homeowner,\"12 Main St\",2025-06-01,sump pump replacement;French Drain,Replaced the pump.\nJohn Q,\"9 Oak Ave\",,,\n";

    Livewire::test(JobsBoard::class)
        ->set('csvFile', UploadedFile::fake()->createWithContent('jobs.csv', $csv))
        ->call('importCsv');

    $jobs = Job::withoutGlobalScopes()->where('site_id', $site->id)->get();
    expect($jobs)->toHaveCount(2);
    $jane = $jobs->firstWhere('client_name_full', 'Jane Homeowner');
    expect($jane->jobTypes()->orderBy('label')->pluck('label')->all())->toBe(['French Drain', 'Sump Pump Replacement'])
        ->and($jane->jobTypes()->where('slug', 'sump-pump-replacement')->value('job_type_id'))->not->toBeNull();
});

it('edits a queued job in place: client, date, services, write-up', function () {
    $site = Site::factory()->create();
    Service::factory()->create(['site_id' => $site->id, 'name' => 'French Drain']);
    app(ActiveTenant::class)->set($site->id);
    $job = reviewJob($site);
    $job->jobTypes()->create(['label' => 'Old Type', 'slug' => 'old-type']);

    Livewire::test(JobsBoard::class)
        ->call('startEdit', $job->id)
        ->assertSet('editJobTypesOther', 'Old Type')     // a non-catalog type shows in the free-text box
        ->set('editClientName', 'Jane Homeowner')
        ->set('editPerformedAt', '2025-04-14')
        ->set('editJobTypeLabels', ['French Drain'])
        ->set('editJobTypesOther', '')
        ->set('editTitle', 'Drain fixed')
        ->call('saveEdits')
        ->assertSet('editingId', null);

    $job->refresh();
    expect($job->client_name_display)->toBe('Jane H.')
        ->and($job->performed_at->toDateString())->toBe('2025-04-14')
        ->and($job->post_title)->toBe('Drain fixed')
        ->and($job->jobTypes()->pluck('label')->all())->toBe(['French Drain']);
});

it('attaches uploaded photos to a queued job from the board', function () {
    Storage::fake('r2');
    $site = Site::factory()->create();
    app(ActiveTenant::class)->set($site->id);
    $job = reviewJob($site);

    Livewire::test(JobsBoard::class)
        ->set('jobPhotos.'.$job->id, [UploadedFile::fake()->image('after.jpg', 20, 20)])
        ->call('attachPhotos', $job->id);

    expect($job->refresh()->photos)->toHaveCount(1);
});

it('renders the workbench card with photos, services, and the write-up columns', function () {
    $site = Site::factory()->create();
    app(ActiveTenant::class)->set($site->id);
    $job = reviewJob($site);
    $job->forceFill(['photos' => [['r2_key' => 'k1', 'alt' => 'The new pump']], 'raw_description' => 'tech notes here'])->save();
    $job->jobTypes()->create(['label' => 'Sump Pump Replacement', 'slug' => 'sump-pump-replacement']);

    $html = Livewire::test(JobsBoard::class)->assertOk()->html();

    expect($html)->toContain('Sump Pump Replacement')->toContain('tech notes here')->toContain('Add photos')->toContain('Re-place');
});
