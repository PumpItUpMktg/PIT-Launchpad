<?php

use App\Integrations\Google\SearchConsoleProvider;
use App\Mail\MonthlyReportMail;
use App\Models\Account;
use App\Models\Site;

beforeEach(function () {
    app()->instance(SearchConsoleProvider::class, new class implements SearchConsoleProvider
    {
        public function searchAnalytics(Site $site, DateTimeInterface $start, DateTimeInterface $end, array $dimensions = ['query'], int $rowLimit = 1000): array
        {
            return [];
        }
    });
});

it('is white-labeled in the subject and attaches the report PDF', function () {
    $account = Account::factory()->create(['brand_name' => 'Acme Plumbing']);
    $site = Site::factory()->create(['account_id' => $account->id, 'brand_name' => 'Acme NJ']);

    $mail = new MonthlyReportMail($site, '2026-06');

    // Brand, not "Launchpad", in the subject; the month label is the report month.
    expect($mail->envelope()->subject)->toBe('Acme Plumbing — Your June 2026 performance report');

    // Exactly one PDF attachment, brand + month named, rendered from the shared view-model.
    $attachments = $mail->attachments();
    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]->as)->toBe('acme-nj-2026-06.pdf')
        ->and($attachments[0]->mime)->toBe('application/pdf');
});

it('renders the body without over-claiming (observed movement, never guaranteed/attributed)', function () {
    $account = Account::factory()->create(['brand_name' => 'Acme Plumbing']);
    $site = Site::factory()->create(['account_id' => $account->id]);

    $rendered = (new MonthlyReportMail($site, '2026-06'))->render();
    $lower = strtolower($rendered);

    expect($lower)->toContain('observed')
        ->not->toContain('guaranteed')
        ->not->toContain('attributed')
        ->not->toContain('roi');
});
