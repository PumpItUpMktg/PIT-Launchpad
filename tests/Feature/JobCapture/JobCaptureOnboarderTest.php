<?php

use App\Enums\ProductPlan;
use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\JobCapture\Onboarding\JobCaptureOnboarder;
use App\Models\Account;
use App\Models\Site;
use App\Onboarding\WordpressPrep;
use App\Operator\Controls\WordpressConnector;

beforeEach(function () {
    // Fake the shared WP-prep seam (no live WordPress in tests); $ok toggles connect, and a successful
    // connect marks the site connected for isConnected()/activation.
    $this->prep = new class(app(WordpressConnector::class)) extends WordpressPrep
    {
        public bool $ok = true;

        public bool $connected = false;

        public function prep(Site $site, array $input): array
        {
            $this->connected = $this->ok;

            return ['ready' => $this->ok, 'steps' => [], 'error' => $this->ok ? null : 'bad creds'];
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

it('creates a standalone Job Capture tenant (Account + Site) still in onboarding', function () {
    $site = app(JobCaptureOnboarder::class)->createClient('Ace Group', 'Ace Plumbing', 'https://ace.com');

    expect($site->product)->toBe(ProductPlan::JobCapture)
        ->and($site->status)->toBe(SiteStatus::Onboarding)
        ->and($site->brand_name)->toBe('Ace Plumbing')
        ->and($site->domain_url)->toBe('https://ace.com')
        ->and(Account::query()->whereKey($site->account_id)->value('name'))->toBe('Ace Group');
});

it('defaults the account name to the business name when none is given', function () {
    $site = app(JobCaptureOnboarder::class)->createClient('', 'Ace Plumbing');

    expect(Account::query()->whereKey($site->account_id)->value('name'))->toBe('Ace Plumbing');
});

it('connects WordPress through the shared prep process', function () {
    $site = app(JobCaptureOnboarder::class)->createClient('Ace', 'Ace');

    $result = app(JobCaptureOnboarder::class)->connectWordpress($site, [
        'base_url' => 'https://ace.com', 'username' => 'admin', 'app_password' => 'abcd efgh',
    ]);

    expect($result['ready'])->toBeTrue()
        ->and(app(JobCaptureOnboarder::class)->canActivate($site))->toBeTrue();
});

it('will not activate before WordPress is connected, then activates once it is', function () {
    $onboarder = app(JobCaptureOnboarder::class);
    $site = $onboarder->createClient('Ace', 'Ace');

    expect($onboarder->activate($site))->toBeFalse()
        ->and($site->fresh()->status)->toBe(SiteStatus::Onboarding);

    $onboarder->connectWordpress($site, ['base_url' => 'https://ace.com', 'username' => 'admin', 'app_password' => 'x']);

    expect($onboarder->activate($site))->toBeTrue()
        ->and($site->fresh()->status)->toBe(SiteStatus::Active);
});

it('onboards a tech as a role=tech user linked to a capture device', function () {
    $site = app(JobCaptureOnboarder::class)->createClient('Ace', 'Ace');

    $result = app(JobCaptureOnboarder::class)->addTech($site, 'Mike R.', 'mike@ace.com');

    expect($result['code'])->toMatch('/^\d{6}$/')
        ->and($result['device']->site_id)->toBe($site->id)
        ->and($result['device']->user->role)->toBe(UserRole::Tech);
});
