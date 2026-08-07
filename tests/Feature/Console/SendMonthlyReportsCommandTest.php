<?php

use App\Enums\UserRole;
use App\Integrations\Google\SearchConsoleProvider;
use App\Mail\MonthlyReportMail;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ClientHarness;

afterEach(fn () => Carbon::setTestNow());

beforeEach(function () {
    app()->instance(SearchConsoleProvider::class, new class implements SearchConsoleProvider
    {
        public function searchAnalytics(Site $site, DateTimeInterface $start, DateTimeInterface $end, array $dimensions = ['query'], int $rowLimit = 1000, int $startRow = 0): array
        {
            return [];
        }
    });
});

it('emails the prior month to each client user, keyed to the report month', function () {
    Carbon::setTestNow(Carbon::create(2026, 7, 3));
    Mail::fake();

    ['user' => $client, 'site' => $site] = ClientHarness::make();

    $this->artisan('launchpad:send-monthly-reports')->assertSuccessful();

    Mail::assertQueued(MonthlyReportMail::class, function (MonthlyReportMail $mail) use ($client, $site) {
        return $mail->hasTo($client->email)
            && $mail->site->is($site)
            && $mail->monthKey === '2026-06'; // July run reports June
    });
});

it('honors an explicit --month and only the given --site', function () {
    Mail::fake();

    $a = ClientHarness::make();
    $b = ClientHarness::make();

    $this->artisan('launchpad:send-monthly-reports', ['--month' => '2026-05', '--site' => $a['site']->id])
        ->assertSuccessful();

    Mail::assertQueued(MonthlyReportMail::class, fn (MonthlyReportMail $m) => $m->site->is($a['site']) && $m->monthKey === '2026-05');
    Mail::assertNotQueued(MonthlyReportMail::class, fn (MonthlyReportMail $m) => $m->site->is($b['site']));
});

it('skips a site whose account has no client users, and sends nothing on --dry-run', function () {
    Mail::fake();

    // Account + site but the only user is an operator — no client recipient.
    ['user' => $user] = ClientHarness::make();
    $user->update(['role' => UserRole::Operator]);

    $this->artisan('launchpad:send-monthly-reports', ['--month' => '2026-06'])->assertSuccessful();
    Mail::assertNothingQueued();

    // A real client, but --dry-run: still nothing sent.
    ClientHarness::make();
    $this->artisan('launchpad:send-monthly-reports', ['--month' => '2026-06', '--dry-run' => true])->assertSuccessful();
    Mail::assertNothingQueued();
});
