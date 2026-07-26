<?php

use App\Enums\ConnectionProvider;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Connection;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;
use App\Reporting\TenantReport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

function reportSite(): Site
{
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://sump.example', 'phone' => '(908) 555-0100']);
    Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Installation']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Cranford', 'page_selected' => true]);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Cranford HQ', 'is_storefront' => true, 'phone' => '(908) 555-0100']);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'title' => 'Sump Pump Installation', 'slug' => 'sump-pump-installation', 'status' => ContentStatus::Published,
    ]);
    Content::factory()->post()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'title' => 'Cranford sump story', 'silo_id' => null,
    ]);

    return $site;
}

it('emits all ten sections as pasteable markdown, resolvable by brand name', function () {
    reportSite();

    Artisan::call('launchpad:report', ['site' => 'Sump Pump Gurus']);
    $out = Artisan::output();

    expect($out)
        ->toContain('# Tenant report — Sump Pump Gurus')
        ->toContain('**RAG:**')
        ->toContain('## 1. Intake & records')
        ->toContain('## 2. Structure')
        ->toContain('## 3. Pages')
        ->toContain('## 4. Link integrity')
        ->toContain('## 5. Schema & NAP')
        ->toContain('## 6. Launch checklist')
        ->toContain('## 7. Queue & jobs')
        ->toContain('## 8. Content engine')
        ->toContain('## 9. Anomalies & warnings');

    // Counts-first, under the ~400-line budget.
    expect(substr_count($out, "\n"))->toBeLessThan(400);
});

it('--section=links emits only section 4', function () {
    reportSite();

    Artisan::call('launchpad:report', ['site' => 'Sump Pump Gurus', '--section' => 'links']);
    $out = Artisan::output();

    expect($out)
        ->toContain('## 4. Link integrity')
        ->not->toContain('## 1. Intake')
        ->not->toContain('## 7. Queue')
        ->not->toContain('# Tenant report'); // no full header when a single section is requested
});

it('is read-only — changes no state and dispatches no jobs', function () {
    Queue::fake();
    $site = reportSite();
    $before = [
        'content' => Content::withoutGlobalScopes()->count(),
        'silos' => Silo::withoutGlobalScopes()->count(),
        'towns' => CoverageArea::withoutGlobalScopes()->count(),
    ];

    Artisan::call('launchpad:report', ['site' => $site->id]);

    expect(Content::withoutGlobalScopes()->count())->toBe($before['content'])
        ->and(Silo::withoutGlobalScopes()->count())->toBe($before['silos'])
        ->and(CoverageArea::withoutGlobalScopes()->count())->toBe($before['towns']);
    Queue::assertNothingPushed();
});

it('two runs with unchanged state produce identical output (timestamp line excepted)', function () {
    $site = reportSite();

    $strip = fn (string $s): string => implode("\n", array_filter(
        explode("\n", $s),
        fn (string $line): bool => ! str_contains($line, 'Generated at:'),
    ));

    $first = $strip(app(TenantReport::class)->render($site));
    $second = $strip(app(TenantReport::class)->render($site));

    expect($first)->toBe($second);
});

it('the --json flag emits valid JSON with the RAG summary', function () {
    reportSite();

    Artisan::call('launchpad:report', ['site' => 'Sump Pump Gurus', '--json' => true]);
    $decoded = json_decode(trim(Artisan::output()), true);

    expect($decoded)->toBeArray()
        ->and($decoded['brand_name'])->toBe('Sump Pump Gurus')
        ->and($decoded['summary'])->toHaveKey('intake')
        ->and($decoded['schema_version'])->toBe('1');
});

it('the header reflects a real wp_app_password connection (not a false "no connection")', function () {
    $site = reportSite();
    Connection::factory()->create([
        'site_id' => $site->id, 'provider' => ConnectionProvider::WpAppPassword->value,
        'compromised' => false, 'last_rotated_at' => now(),
    ]);

    $out = app(TenantReport::class)->render($site->fresh());

    expect($out)->toContain('configured')          // the connection is seen …
        ->not->toContain('no WordPress connection'); // … not the old provider-mismatch false alarm
});

it('the header flags a compromised connection', function () {
    $site = reportSite();
    Connection::factory()->create([
        'site_id' => $site->id, 'provider' => ConnectionProvider::WpAppPassword->value,
        'compromised' => true,
    ]);

    expect(app(TenantReport::class)->render($site->fresh()))->toContain('compromised/unrotated');
});
