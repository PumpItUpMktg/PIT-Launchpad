<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IntakeType;
use App\Models\Content;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Artisan;

afterEach(fn () => CurrentSite::clear());

it('refuses to draft a candidate whose coverage verdict names a live post, unless --force', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $candidate = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'intake_type' => IntakeType::Reactive, 'status' => ContentStatus::Candidate, 'body' => null,
        'title' => 'Sump Pump Maintenance Checklist This Fall',
        'meta' => ['coverage' => ['verdict' => 'covered', 'twin_id' => 'x', 'twin_title' => 'Sump Pump Maintenance Checklist', 'reason' => 'It ranks third.']],
    ]);

    $code = Artisan::call('launchpad:generate-post', ['content' => $candidate->id]);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('Covered — “Sump Pump Maintenance Checklist” already covers this topic. It ranks third.')->toContain('--force')
        ->and($candidate->fresh()->status)->toBe(ContentStatus::Candidate)
        ->and($candidate->fresh()->hasDraft())->toBeFalse();
});
