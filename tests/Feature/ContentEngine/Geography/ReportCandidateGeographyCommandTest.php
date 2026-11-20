<?php

use App\ContentEngine\Geography\CandidateGeographyReport;
use App\ContentEngine\Geography\PlaceExtractor;
use App\Enums\ContentStatus;
use App\Enums\PlaceVerdict;
use App\Models\Content;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\ScriptedClaudeClient;

it('reads stamped candidates as stamped, extracts a place for the rest, and reports the verdict counts without writing', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'corporate_state' => 'NJ']);
    $post = fn (string $title, array $meta = [], ContentStatus $status = ContentStatus::Candidate) => Content::factory()->post()->create([
        'site_id' => $site->id, 'title' => $title, 'status' => $status, 'source_name' => 'Local Tribune', 'meta' => $meta,
    ]);
    $post('Omaha homeowners report sewer backups');                                  // read now → outside
    $post('Buffalo basements flood', ['place_scope' => 'region', 'story_place' => 'Buffalo, NY']); // stamped
    $post('Camden crews clear storm drains', [], ContentStatus::InReview);          // read now → footprint
    $post('Best sump pumps of 2026');                                                // read now → placeless
    $post('An old published post', [], ContentStatus::Published);                   // not a candidate — ignored

    $claude = (new ScriptedClaudeClient)
        ->on('Omaha', '{"story_place":"Omaha, NE","story_state":"NE"}')
        ->on('Camden', '{"story_place":"Camden, NJ","story_state":"NJ"}')
        ->fallback('{"story_place":null,"story_state":null}');
    $this->app->instance(PlaceExtractor::class, new PlaceExtractor($claude));

    $r = app(CandidateGeographyReport::class)->report($site);
    expect($r['counts'])->toBe(['footprint' => 1, 'region' => 1, 'outside' => 1, 'unknown' => 1])
        ->and($r['extracted'])->toBe(3)
        ->and(collect($r['rows'])->firstWhere('title', 'Buffalo basements flood')['stamped'])->toBeTrue()
        ->and(collect($r['rows'])->firstWhere('title', 'Omaha homeowners report sewer backups')['verdict'])->toBe(PlaceVerdict::Outside)
        ->and(count($claude->prompts))->toBe(3)
        ->and($claude->prompts[0])->toContain('Outlet: Local Tribune');

    Artisan::call('launchpad:report-candidate-geography', ['--site' => 'Sump Pump Gurus']);
    $out = Artisan::output();
    expect($out)->toContain('Footprint: NJ · region ring: DE, NY, PA')
        ->toContain('4 candidate(s): 1 in footprint · 1 region · 1 out of market · 1 placeless (3 read by one model call each')
        ->toContain('Out of market Omaha homeowners report sewer backups · Omaha, NE (read now)')
        ->toContain('Region        Buffalo basements flood · Buffalo, NY')
        ->not->toContain('Camden crews');   // in-footprint rows are listed only with --all

    Artisan::call('launchpad:report-candidate-geography', ['--site' => $site->id, '--all' => true]);
    expect(Artisan::output())->toContain('In footprint  Camden crews clear storm drains');

    expect(Content::withoutGlobalScopes()->where('site_id', $site->id)->whereNotNull('meta->place_scope')->count())->toBe(1); // nothing written
});
