<?php

use App\Enums\ProductPlan;
use App\Enums\SiteStatus;
use App\Filament\Console\Pages\JobCaptureSetup;
use App\Models\Site;
use App\Models\User;
use App\Onboarding\WordpressPrep;
use App\Operator\Controls\WordpressConnector;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create()); // Operator (Super Admin) by default

    $this->prep = new class(app(WordpressConnector::class)) extends WordpressPrep
    {
        public bool $connected = false;

        public function prep(Site $site, array $input): array
        {
            $this->connected = true;

            return ['ready' => true, 'steps' => [], 'error' => null];
        }

        public function isConnected(Site $site): bool
        {
            return $this->connected;
        }

        public function status(Site $site): array
        {
            return ['Connected & verified' => $this->connected];
        }
    };
    app()->instance(WordpressPrep::class, $this->prep);
});

it('is reachable only by the Super Admin tier', function () {
    expect(JobCaptureSetup::canAccess())->toBeTrue();

    $this->actingAs(User::factory()->siteAdmin()->create());
    expect(JobCaptureSetup::canAccess())->toBeFalse();
});

it('creates a standalone Job Capture client and holds it as the working site', function () {
    $page = new JobCaptureSetup;
    $page->newBrandName = 'Ace Plumbing';
    $page->newDomain = 'https://ace.com';
    $page->createClient();

    $site = Site::withoutGlobalScopes()->where('brand_name', 'Ace Plumbing')->first();

    expect($site)->not->toBeNull()
        ->and($site->product)->toBe(ProductPlan::JobCapture)
        ->and($page->siteId)->toBe($site->id)
        ->and($page->baseUrl)->toBe('https://ace.com'); // seeded from the domain
});

it('adds a tech and surfaces the capture link + code', function () {
    $page = new JobCaptureSetup;
    $page->newBrandName = 'Ace';
    $page->createClient();

    $page->techName = 'Mike R.';
    $page->techEmail = 'mike@ace.com';
    $page->addTech();

    expect($page->lastIssued['code'])->toMatch('/^\d{6}$/')
        ->and($page->lastIssued['link'])->toContain('/capture?device=')
        ->and($page->techName)->toBe(''); // form cleared
});

it('blocks activation before WordPress is connected', function () {
    $page = new JobCaptureSetup;
    $page->newBrandName = 'Ace';
    $page->createClient();
    $site = Site::withoutGlobalScopes()->where('brand_name', 'Ace')->first();

    $page->activate();

    expect($site->fresh()->status)->toBe(SiteStatus::Onboarding); // still onboarding — not connected
});

it('connects then activates the client', function () {
    $page = new JobCaptureSetup;
    $page->newBrandName = 'Ace';
    $page->createClient();
    $site = Site::withoutGlobalScopes()->where('brand_name', 'Ace')->first();

    $page->baseUrl = 'https://ace.com';
    $page->username = 'admin';
    $page->appPassword = 'abcd efgh';
    $page->connectWordpress();

    expect($page->connected)->toBeTrue()
        ->and($page->appPassword)->toBe(''); // secret cleared

    $page->activate();
    expect($site->fresh()->status)->toBe(SiteStatus::Active);
});

it('renders the page (compiles the blade)', function () {
    Livewire::test(JobCaptureSetup::class)
        ->assertOk()
        ->assertSee('New Job Capture client');
});
