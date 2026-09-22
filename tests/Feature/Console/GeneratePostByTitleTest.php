<?php

use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Drafting\Drafter;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\DraftTrigger;
use App\Enums\IntakeType;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use App\Publishing\RenderCoordinator;
use App\Publishing\RenderOutcome;
use App\Support\CurrentSite;
use Illuminate\Support\Collection;
use Tests\Support\Draft;
use Tests\Support\FakeClaudeClient;

afterEach(function () {
    CurrentSite::clear();
});

/** A revival candidate as LegacyContentReviver leaves it: a post, status candidate, briefed, undrafted. */
function revivalCandidate(Site $site, string $title, string $from): Content
{
    return Content::factory()->create([
        'site_id' => $site->id,
        'silo_id' => Silo::factory()->create(['site_id' => $site->id])->id,
        'kind' => ContentKind::Post,
        'intake_type' => IntakeType::Reactive,
        'draft_trigger' => DraftTrigger::OnDemand,
        'status' => ContentStatus::Candidate,
        'title' => $title,
        'body' => null,
        'source_url' => $from,
        'angle_hint' => 'Revive a top-performing legacy article.',
        'meta' => ['revived_from_urls' => [$from], 'revived_query' => mb_strtolower($title)],
    ]);
}

function fakeGeneration(): void
{
    app()->bind(Drafter::class, fn () => new Drafter(new DraftCall(new FakeClaudeClient(Draft::json([
        'body' => '<p>What a sump pump replacement actually costs in 2026, line by line, and where the quotes diverge.</p>',
    ])))));
    $renders = Mockery::mock(RenderCoordinator::class);
    $renders->shouldReceive('render')->andReturn(new RenderOutcome(new Collection, true, []));
    app()->instance(RenderCoordinator::class, $renders);
}

it('generates a candidate named by title inside a tenant, case-insensitively', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $candidate = revivalCandidate($site, 'Sump Pump Renovation Cost', '/sump-pump-installation-cost-breakdown-3');
    fakeGeneration();

    // The operator can read the title off the Blog board; the ULID behind it is not shown anywhere.
    test()->artisan('launchpad:generate-post', ['--title' => 'sump pump renovation cost', '--site' => 'Sump Pump Gurus'])
        ->expectsOutputToContain("Generated '")
        ->assertSuccessful();

    expect($candidate->fresh()->hasDraft())->toBeTrue();
});

it('refuses an ambiguous title and lists each match by id', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    // Two different articles, one title — the pump-capacity one and the pipe-size one. Picking either
    // silently would generate the wrong family.
    $a = revivalCandidate($site, 'What Size Sump Pump Do I Need', '/sump-pump-size-matters-choosing-right-one-for-home');
    $b = revivalCandidate($site, 'What Size Sump Pump Do I Need', '/check-if-sump-pump-fits-drainage-pipe');

    test()->artisan('launchpad:generate-post', ['--title' => 'What Size Sump Pump Do I Need', '--site' => $site->id])
        ->expectsOutputToContain('2 posts are titled')
        ->expectsOutputToContain((string) $a->id)
        ->expectsOutputToContain((string) $b->id)
        ->assertFailed();

    expect($a->fresh()->hasDraft())->toBeFalse()->and($b->fresh()->hasDraft())->toBeFalse();
});

it('does not resolve a title across tenants', function () {
    $mine = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $theirs = Site::factory()->create(['brand_name' => 'Other Co']);
    revivalCandidate($theirs, 'Sump Pump Renovation Cost', '/cost');

    test()->artisan('launchpad:generate-post', ['--title' => 'Sump Pump Renovation Cost', '--site' => $mine->id])
        ->expectsOutputToContain('No post titled')
        ->assertFailed();
});

it('insists on a tenant, because titles are only unique inside one', function () {
    test()->artisan('launchpad:generate-post', ['--title' => 'Sump Pump Renovation Cost'])
        ->expectsOutputToContain('--title needs --site')
        ->assertFailed();
});

it('still resolves the original label after the drafter has retitled the post', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $post = revivalCandidate($site, 'Sump Pump Renovation Cost', '/sump-pump-installation-cost-breakdown-3');
    // Drafted once already: the title is now the generated SEO title, the label lives in meta.
    $post->forceFill([
        'title' => 'How Much Does a Sump Pump Cost to Install in 2026?',
        'body' => '<p>drafted</p>',
        'status' => ContentStatus::NeedsReview,
    ])->save();
    fakeGeneration();

    // Without --regenerate it must not overwrite the draft — and must say that is why.
    test()->artisan('launchpad:generate-post', ['--title' => 'Sump Pump Renovation Cost', '--site' => $site->id])
        ->expectsOutputToContain('already drafted')
        ->assertFailed();

    test()->artisan('launchpad:generate-post', ['--title' => 'Sump Pump Renovation Cost', '--site' => $site->id, '--regenerate' => true])
        ->expectsOutputToContain("Generated '")
        ->assertSuccessful();
});

it('lists the revival candidates with ids when the title does not resolve', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $a = revivalCandidate($site, 'Sump Pump Alarm Going Off', '/sump-pump-alarm-system-troubleshooting-guide');

    // A dead end that names nothing to try instead is not an error message.
    test()->artisan('launchpad:generate-post', ['--title' => 'Sump Pump Renovation Cost', '--site' => $site->id])
        ->expectsOutputToContain('No post titled')
        ->expectsOutputToContain('Revival candidates on Sump Pump Gurus')
        ->expectsOutputToContain((string) $a->id)
        ->assertFailed();
});
