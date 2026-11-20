<?php

use App\Enums\ContentStatus;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Integrations\Embedding\MockEmbeddingProvider;
use App\Models\Content;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;

it('reports the verdicts without writing, then stamps them with --execute', function () {
    app()->instance(EmbeddingProvider::class, new MockEmbeddingProvider);
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://spg.example']);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'title' => 'Sump Pump Maintenance Checklist for Homeowners', 'slug' => 'sump-pump-maintenance-checklist', 'body' => '<p>b</p>']);
    $twinned = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Candidate, 'title' => 'Sump Pump Maintenance Checklist for Homeowners This Fall', 'body' => null, 'meta' => []]);
    $fresh = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Candidate, 'title' => 'Radon fan noise at night', 'body' => null, 'meta' => []]);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Rejected, 'title' => 'Sump Pump Maintenance Checklist for Homeowners Again', 'body' => null, 'meta' => []]);

    Artisan::call('launchpad:assess-coverage', ['--site' => 'Sump Pump Gurus']);
    $out = Artisan::output();
    expect($out)->toContain('Read-only')
        ->toContain('Refresh existing')
        ->toContain('Sump Pump Maintenance Checklist for Homeowners This Fall  →  “Sump Pump Maintenance Checklist for Homeowners” (no GSC standing')
        ->toContain('1 new post · 1 refresh existing · 0 covered — nothing written')
        ->not->toContain('Radon fan noise');   // author rows are not listed
    expect($twinned->fresh()->meta)->not->toHaveKey('coverage')->and($fresh->fresh()->meta)->not->toHaveKey('coverage');

    Artisan::call('launchpad:assess-coverage', ['--site' => $site->id, '--execute' => true]);
    expect(Artisan::output())->toContain('stamped on the rows');
    expect($twinned->fresh()->meta['coverage']['verdict'])->toBe('refresh')->and($fresh->fresh()->meta['coverage']['verdict'])->toBe('author');

    // Stamped rows are skipped unless --all.
    Artisan::call('launchpad:assess-coverage', ['--site' => $site->id]);
    expect(Artisan::output())->toContain('0 new post · 0 refresh existing · 0 covered');
    Artisan::call('launchpad:assess-coverage', ['--site' => $site->id, '--all' => true]);
    expect(Artisan::output())->toContain('1 new post · 1 refresh existing');
});
