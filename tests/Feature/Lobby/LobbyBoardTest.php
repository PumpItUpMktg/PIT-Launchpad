<?php

use App\Enums\BlogTargetStatus;
use App\Enums\CitationPresence;
use App\Enums\ConnectionProvider;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\JobStatus;
use App\Enums\LobbyBadgeTier;
use App\Enums\LobbyCardState;
use App\Enums\ReviewStatus;
use App\Enums\SiteStatus;
use App\Enums\VoiceStatus;
use App\Models\BlogTarget;
use App\Models\CitationStatus;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Job;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Market;
use App\Models\Review;
use App\Models\Service;
use App\Models\SetupState;
use App\Models\Silo;
use App\Models\Site;
use App\Models\VoiceProfile;
use App\Operator\Lobby\LobbyBoard;
use App\Publishing\Chrome\SiteProfileAssembler;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\DB;

afterEach(fn () => CurrentSite::clear());

/** Find a site's card in the board output. */
function lobbyCard(string $siteId, string $filter = 'all')
{
    return app(LobbyBoard::class)->cards('', $filter)->firstWhere(fn ($c) => $c->site->id === $siteId);
}

/** Make a live site setup-complete so the tier-2 setup_gaps badge does not fire (isolates other conditions). */
function completeSetup(Site $site): void
{
    Service::factory()->create(['site_id' => $site->id]);
    VoiceProfile::factory()->create(['site_id' => $site->id, 'status' => VoiceStatus::Active]);
    // compromised defaults true (§9) — a broken WP connection is its own tier-1 badge, so keep it clean here.
    Connection::factory()->create(['site_id' => $site->id, 'provider' => ConnectionProvider::WpAppPassword, 'compromised' => false]);
    // Chrome pushed too — a fully set-up site isn't "chrome never synced" and isn't drifted, so the tier-2
    // chrome badges don't fire in the tiering tests.
    $site->markChromeSynced(SiteProfileAssembler::fingerprint(app(SiteProfileAssembler::class)->assemble($site->fresh())));
}

it('assembles the lobby in a constant number of queries — no per-card query (acceptance 15)', function () {
    // Two sites with mixed conditions.
    foreach (range(1, 2) as $i) {
        $s = Site::factory()->create(['status' => SiteStatus::Active]);
        Content::factory()->create(['site_id' => $s->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::NeedsReview]);
        Review::factory()->create(['site_id' => $s->id, 'status' => ReviewStatus::Pending]);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    app(LobbyBoard::class)->cards();
    $twoSiteQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Six more sites, same shape.
    foreach (range(1, 6) as $i) {
        $s = Site::factory()->create(['status' => SiteStatus::Active]);
        Content::factory()->create(['site_id' => $s->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::NeedsReview]);
        Review::factory()->create(['site_id' => $s->id, 'status' => ReviewStatus::Pending]);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    app(LobbyBoard::class)->cards();
    $eightSiteQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Constant regardless of tenant count → no per-card query. (The bound rose from 12 to 16 when the
    // starved-queues subquery + the four setup-gap aggregates were absorbed from the retired dashboard.)
    expect($eightSiteQueries)->toBe($twoSiteQueries)
        ->and($twoSiteQueries)->toBeLessThanOrEqual(18);
});

it('flips a card to Blocked on a Tier-1 condition and suppresses lower tiers into "+N more" (acceptance 12)', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Active]);
    completeSetup($site); // isolate the tiering scenario from the tier-2 setup_gaps badge
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::RenderFailed]); // Tier 1
    Review::factory()->create(['site_id' => $site->id, 'status' => ReviewStatus::Pending]);          // Tier 3

    $card = lobbyCard($site->id);

    expect($card->state)->toBe(LobbyCardState::Blocked)
        ->and($card->visibleBadges())->toHaveCount(1)
        ->and($card->visibleBadges()[0]->tier)->toBe(LobbyBadgeTier::BrokenBlocking)
        ->and($card->moreCount())->toBe(1)
        ->and($card->moreLabel())->toContain('none publishable until the connection is fixed');
});

it('shows up to three tier-ordered badges then "+N more"; colour is the tier, never the count (acceptance 13)', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Active]);
    completeSetup($site); // no setup_gaps badge — this test is about tier ordering, not setup
    $loc = Location::factory()->for($site)->create(['served_towns' => [['name' => 'Trenton', 'state' => 'NJ']]]);
    // Four non-Tier-1 conditions across tiers 2-4.
    Market::factory()->create(['site_id' => $site->id, 'on_hold' => true, 'release_at' => now()->subDay()]); // T2
    CitationStatus::factory()->create(['site_id' => $site->id, 'location_id' => $loc->id, 'presence' => CitationPresence::PresentMismatch, 'covered_by_sibling' => false]); // T2
    // Ten pending reviews (T3) — a big count that must NOT outrank a red tier.
    Review::factory()->count(10)->create(['site_id' => $site->id, 'status' => ReviewStatus::Pending]);
    Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Review]); // T3

    $card = lobbyCard($site->id);

    expect($card->state)->toBe(LobbyCardState::ActivePending)
        ->and($card->visibleBadges())->toHaveCount(3)
        ->and($card->moreCount())->toBe(1)
        ->and($card->moreLabel())->toBe('+1 more')
        // Tier order: the two Tier-2 (danger) badges lead the Tier-3 ones, regardless of the 10-count.
        ->and($card->visibleBadges()[0]->tier)->toBe(LobbyBadgeTier::WrongData)
        ->and($card->visibleBadges()[0]->color())->toBe('danger');

    // The 10-pending-reviews badge is amber (its tier), not escalated by its count.
    $reviews = collect($card->badges)->firstWhere('key', 'reviews_pending');
    expect($reviews->count)->toBe(10)->and($reviews->color())->toBe('warning');
});

it('an active site with nothing waiting is ActiveClean with no badges', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Active]);
    completeSetup($site); // a genuinely clean live site is setup-complete (no setup_gaps badge)

    $card = lobbyCard($site->id);

    expect($card->state)->toBe(LobbyCardState::ActiveClean)
        ->and($card->badges)->toBe([])
        ->and($card->needsAttention())->toBeFalse();
});

it('an onboarding site is a progress card — no operational badges even with pending work', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Onboarding]);
    SetupState::factory()->create(['site_id' => $site->id, 'current_step' => 3]);
    Review::factory()->create(['site_id' => $site->id, 'status' => ReviewStatus::Pending]); // would badge if active

    $card = lobbyCard($site->id);

    expect($card->state)->toBe(LobbyCardState::Onboarding)
        ->and($card->onboardingStep)->toBe(3)
        ->and($card->onboardingStepCount)->toBeGreaterThan(0)
        ->and($card->badges)->toBe([]);
});

it('counts exclude soft-deleted rows (acceptance 17)', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Active]);
    $live = Review::factory()->create(['site_id' => $site->id, 'status' => ReviewStatus::Pending]);
    $gone = Review::factory()->create(['site_id' => $site->id, 'status' => ReviewStatus::Pending]);
    $gone->delete(); // soft delete

    $card = lobbyCard($site->id);
    $reviews = collect($card->badges)->firstWhere('key', 'reviews_pending');

    expect($reviews->count)->toBe(1);
});

it('does not badge raw blog candidates — only posts awaiting review', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Active]);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Candidate]); // not badged
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::NeedsReview]); // badged

    $card = lobbyCard($site->id);
    $blog = collect($card->badges)->firstWhere('key', 'blog_review');

    expect($blog)->not->toBeNull()->and($blog->count)->toBe(1);
});

// The two badges absorbed from the retired cross-tenant OperateDashboard (tenant-lock remediation).

it('badges a live site whose blog queue has run dry — tier 4, degrading (from the retired dashboard)', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Active]);
    completeSetup($site); // isolate the starved-queue badge from setup_gaps
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id]);
    // A participating silo (has held a target) whose queue is empty (0 queued ≤ near-empty) → starved.
    BlogTarget::factory()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'keyword_id' => $kw->id,
        'status' => BlogTargetStatus::Published,
    ]);

    $card = lobbyCard($site->id);
    $badge = collect($card->badges)->firstWhere('key', 'starved_queues');

    expect($badge)->not->toBeNull()
        ->and($badge->tier)->toBe(LobbyBadgeTier::Degrading)
        ->and($badge->count)->toBe(1);
});

it('badges a live site missing setup — tier 2, wrong data reaching the public (from the retired dashboard)', function () {
    // A launched tenant with NO service, NO active voice, NO WP connection, and a location that serves no
    // towns → four readiness gaps. This is "publishing while incomplete", not onboarding progress.
    $live = Site::factory()->create(['status' => SiteStatus::Active]);
    Location::factory()->for($live)->create(['served_towns' => []]);

    $card = lobbyCard($live->id);
    $badge = collect($card->badges)->firstWhere('key', 'setup_gaps');

    expect($badge)->not->toBeNull()
        ->and($badge->tier)->toBe(LobbyBadgeTier::WrongData)
        ->and($badge->count)->toBe(4);

    // An ONBOARDING tenant with the same gaps is a progress card, never a setup_gaps badge.
    $onboarding = Site::factory()->create(['status' => SiteStatus::Onboarding]);
    SetupState::factory()->create(['site_id' => $onboarding->id, 'current_step' => 2]);
    expect(collect(lobbyCard($onboarding->id)->badges)->firstWhere('key', 'setup_gaps'))->toBeNull();
});

it('badges chrome never-synced and chrome drift separately (tier 2)', function () {
    // Never-synced: a WP-connected live site whose chrome was never pushed.
    $never = Site::factory()->create(['status' => SiteStatus::Active]);
    Connection::factory()->create(['site_id' => $never->id, 'provider' => ConnectionProvider::WpAppPassword, 'compromised' => false, 'last_rotated_at' => now()]);
    $neverBadge = collect(lobbyCard($never->id)->badges)->firstWhere('key', 'chrome_never_synced');
    expect($neverBadge)->not->toBeNull()->and($neverBadge->tier)->toBe(LobbyBadgeTier::WrongData);

    // Drifted: synced once, then the persisted chrome_stale flag was set (observer / weekly sweep). Reads as
    // 'chrome_stale', NOT never-synced — the two are separate.
    $drift = Site::factory()->create(['status' => SiteStatus::Active]);
    Connection::factory()->create(['site_id' => $drift->id, 'provider' => ConnectionProvider::WpAppPassword, 'compromised' => false, 'last_rotated_at' => now()]);
    $drift->markChromeSynced('x');
    DB::table('sites')->where('id', $drift->id)->update(['chrome_stale' => true]);
    $badges = collect(lobbyCard($drift->id)->badges);
    expect($badges->firstWhere('key', 'chrome_stale'))->not->toBeNull()
        ->and($badges->firstWhere('key', 'chrome_stale')->tier)->toBe(LobbyBadgeTier::WrongData)
        ->and($badges->firstWhere('key', 'chrome_never_synced'))->toBeNull(); // synced → not never-synced
});

it('searches brand + domain, filters, and sorts most-urgent first (acceptance 11)', function () {
    $urgent = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://gurus.example', 'status' => SiteStatus::Active]);
    Content::factory()->create(['site_id' => $urgent->id, 'status' => ContentStatus::RenderFailed]); // Tier 1 → top
    $calm = Site::factory()->create(['brand_name' => 'Calm Co', 'domain_url' => 'https://calm.example', 'status' => SiteStatus::Active]);
    Review::factory()->create(['site_id' => $calm->id, 'status' => ReviewStatus::Pending]); // Tier 3
    Site::factory()->create(['brand_name' => 'Onb Co', 'domain_url' => 'https://onb.example', 'status' => SiteStatus::Onboarding]);

    $board = app(LobbyBoard::class);

    // Search by domain fragment.
    expect($board->cards('gurus')->pluck('site.id')->all())->toBe([$urgent->id]);
    // Search by brand fragment.
    expect($board->cards('Calm')->pluck('site.id')->all())->toBe([$calm->id]);
    // Attention filter drops the clean onboarding card; urgent (Tier 1) sorts before calm (Tier 3).
    $attention = $board->cards('', 'attention')->pluck('site.id')->all();
    expect($attention)->toBe([$urgent->id, $calm->id]);
    // Onboarding filter keeps only the onboarding card.
    expect($board->cards('', 'onboarding')->every(fn ($c) => $c->state === LobbyCardState::Onboarding))->toBeTrue();
});
