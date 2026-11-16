<?php

use App\Citations\WorkOrder\WorkOrderBuilder;
use App\ContentEngine\Drafting\VoiceResolver;
use App\Enums\CitationLifecycleState;
use App\Enums\CitationPresence;
use App\Enums\UserRole;
use App\Filament\Pages\Citations\CitationsWorkspace;
use App\Links\OutreachKitDrafter;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\OutreachKit;
use App\Models\Site;
use App\Models\TenantDirectoryExclusion;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Support\FakeClaudeClient;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $this->site = Site::factory()->create();
    app(ActiveTenant::class)->set($this->site->id); // the operator works under a tenant lock
    $this->location = Location::factory()->create(['site_id' => $this->site->id, 'name' => 'Bedminster']);
    LocationNapProfile::factory()->create(['site_id' => $this->site->id, 'location_id' => $this->location->id, 'categories' => null]);
    $this->dir = Directory::factory()->create(['name' => 'Yelp', 'domain' => 'yelp.com', 'is_submittable' => true]);
    $this->status = CitationStatus::factory()->create([
        'site_id' => $this->site->id, 'location_id' => $this->location->id, 'directory_id' => $this->dir->id,
        'presence' => CitationPresence::Absent,
    ]);
});

test('the workspace renders the directory rows for a location', function (): void {
    Livewire::test(CitationsWorkspace::class, ['location' => $this->location->id])
        ->assertOk()
        ->assertSee('Yelp');
});

test('create work orders records issuance on the selected citations', function (): void {
    Livewire::test(CitationsWorkspace::class, ['location' => $this->location->id])
        ->call('createWorkOrders', [$this->status->id]);

    expect($this->status->refresh()->work_order_count)->toBe(1);
});

test('mark not relevant writes a tenant exclusion for the whole tenant', function (): void {
    Livewire::test(CitationsWorkspace::class, ['location' => $this->location->id])
        ->call('markNotRelevant', $this->dir->id);

    expect(TenantDirectoryExclusion::query()->where('site_id', $this->site->id)->where('directory_id', $this->dir->id)->exists())->toBeTrue();
});

test('the detail panel drafts a listing kit for the open directory and keeps it', function (): void {
    app()->instance(OutreachKitDrafter::class, new OutreachKitDrafter(claude: new FakeClaudeClient('{"short_description":"Short copy here","long_description":"Long copy here","categories":["Plumber"],"pitch_subject":"s","pitch_body":"Note for the form"}'), voices: app(VoiceResolver::class)));

    Livewire::test(CitationsWorkspace::class, ['location' => $this->location->id])
        ->call('openRow', $this->dir->id)
        ->assertSee('Draft listing kit')
        ->call('draftKit', false)
        ->assertSee('Short copy here')
        ->assertSee('Note for the form')
        ->assertSee('Redraft');

    expect(OutreachKit::withoutGlobalScope(SiteScope::class)->where('location_id', $this->location->id)->where('target_domain', $this->dir->domain)->exists())->toBeTrue();
});

test('the drawer records a hand submission with its URL, and the row leaves the work order', function (): void {
    Livewire::test(CitationsWorkspace::class, ['location' => $this->location->id])
        ->call('openRow', $this->dir->id)
        ->assertSee('Mark submitted')
        ->set('submitUrl', 'https://www.yelp.com/biz/acme-bedminster')
        ->call('markSubmitted')
        ->assertSee('awaiting the next scan');

    $status = $this->status->refresh();
    expect($status->lifecycle)->toBe(CitationLifecycleState::Submitted)
        ->and($status->found_url)->toBe('https://www.yelp.com/biz/acme-bedminster')
        ->and(app(WorkOrderBuilder::class)->build($this->location)->lines)->toBeEmpty();
});

test('the tracker downloads as CSV and a filled one imports as submissions', function (): void {
    Livewire::test(CitationsWorkspace::class, ['location' => $this->location->id])
        ->call('downloadTracker')
        ->assertFileDownloaded('citations-bedminster-'.now()->toDateString().'.csv');

    $csv = "Directory,Status,Live URL,Username/Email,Password,Date Submitted,Verification Method,Notes\nYelp,Missing,https://www.yelp.com/biz/acme-bedminster,,,2026-11-18,Email,done\n";
    Livewire::test(CitationsWorkspace::class, ['location' => $this->location->id])
        ->set('trackerFile', UploadedFile::fake()->createWithContent('tracker.csv', $csv))
        ->call('importTracker')
        ->assertNotified();

    expect($this->status->refresh()->lifecycle)->toBe(CitationLifecycleState::Submitted);
});
