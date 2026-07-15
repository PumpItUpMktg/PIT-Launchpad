<?php

use App\Enums\BlogTargetStatus;
use App\Enums\ConnectionProvider;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\KeywordSource;
use App\Enums\UserRole;
use App\Enums\VoiceStatus;
use App\Filament\Pages\Guided\Grow;
use App\Filament\Pages\Live\LiveLocations;
use App\Filament\Pages\Operate\OperateBlog;
use App\Filament\Pages\Operate\OperateCorePages;
use App\Filament\Pages\Operate\OperateDashboard;
use App\Filament\Pages\Operate\OperateLocationPages;
use App\Filament\Pages\Operate\OperateServicePages;
use App\Jobs\GeneratePost;
use App\Jobs\PublishContent;
use App\Models\BlogTarget;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;
use App\Models\User;
use App\Models\VoiceProfile;
use App\Operate\AttentionBoard;
use App\Operate\BlogBoard;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

function opSite(string $brand = 'SPG'): Site
{
    return Site::factory()->create(['brand_name' => $brand, 'domain_url' => 'https://'.strtolower($brand).'.example']);
}

/** A silo with its pillar page — the "page the articles provide relevance for". */
function opSilo(Site $site, string $name = 'Sump Pumps'): Silo
{
    $pillar = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'title' => $name.' Guide', 'slug' => strtolower(str_replace(' ', '-', $name)),
    ]);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => $name]);
    $silo->forceFill(['pillar_content_id' => $pillar->id])->save();

    return $silo;
}

function opKeyword(Site $site, Silo $silo, string $query): Keyword
{
    return Keyword::factory()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'query' => $query,
        'source' => KeywordSource::Seed, 'target_content_id' => null,
    ]);
}

it('flag off ⇒ Operate hidden; on ⇒ Dashboard · Blog · the three pages boards (Grow/Live untouched)', function () {
    config()->set('launchpad.new_operate_enabled', false);
    expect(OperateDashboard::shouldRegisterNavigation())->toBeFalse()
        ->and(OperateBlog::shouldRegisterNavigation())->toBeFalse()
        ->and(OperateCorePages::shouldRegisterNavigation())->toBeFalse();

    config()->set('launchpad.new_operate_enabled', true);
    expect(OperateDashboard::shouldRegisterNavigation())->toBeTrue()
        ->and(OperateBlog::getNavigationGroup())->toBe('Operate')
        // The pages boards ARE the Operate page surfaces — Core / Service / Location, full lifecycle.
        ->and(OperateCorePages::getNavigationGroup())->toBe('Operate')
        ->and(OperateServicePages::getNavigationGroup())->toBe('Operate')
        ->and(OperateLocationPages::getNavigationGroup())->toBe('Operate')
        // Grow + the old Live boards stay exactly where they were, flag on or off.
        ->and(Grow::getNavigationGroup())->toBeNull()
        ->and(LiveLocations::getNavigationGroup())->toBe('Live');
});

it('the dashboard rolls up attention across tenants and hides clean ones', function () {
    $busy = opSite('BusyCo');
    $silo = opSilo($busy);

    // A GENUINELY clean tenant: setup complete (services, active voice, WP wired), no pipeline
    // work. Setup gaps on a bare tenant are attention by design, so clean means finished.
    $clean = opSite('CleanCo');
    Service::factory()->create(['site_id' => $clean->id]);
    VoiceProfile::factory()->create(['site_id' => $clean->id, 'status' => VoiceStatus::Active]);
    Connection::factory()->create(['site_id' => $clean->id, 'provider' => ConnectionProvider::WpAppPassword]);

    Content::factory()->create(['site_id' => $busy->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::NeedsReview, 'body' => 'draft']);
    Content::factory()->create(['site_id' => $busy->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Candidate]);
    Content::factory()->create(['site_id' => $busy->id, 'kind' => ContentKind::Page, 'status' => ContentStatus::PublishFailed, 'last_publish_error' => 'WP 401']);
    // A participating silo with an empty queue → starved.
    BlogTarget::withoutGlobalScopes()->create([
        'site_id' => $busy->id, 'silo_id' => $silo->id,
        'keyword_id' => opKeyword($busy, $silo, 'consumed one')->id,
        'status' => BlogTargetStatus::Published, 'queued_at' => now()->subDays(9),
    ]);

    $board = app(AttentionBoard::class)->build();

    $tenants = collect($board['rows'])->pluck('tenant');
    expect($tenants)->toContain('BusyCo')
        ->and($tenants)->not->toContain('CleanCo')             // zero attention items ⇒ absent
        ->and($board['totals']['review'])->toBe(1)
        ->and($board['totals']['candidates'])->toBe(1)
        ->and($board['totals']['failures'])->toBe(1)
        ->and($board['totals']['starved_queues'])->toBe(1);

    // Every chip resolves to a click-through URL on the right surface.
    $page = Livewire::test(OperateDashboard::class)->assertOk()->assertSee('BusyCo')->assertDontSee('CleanCo');
    expect($page->instance()->urlFor('review', $busy->id))->toContain('operate/blog')->toContain('tab=review')
        ->and($page->instance()->urlFor('setup_gaps', $busy->id))->toContain('setup2/business');
});

it('candidates sort directed-first and carry the keyword + the page it will support', function () {
    $site = opSite();
    $silo = opSilo($site);
    $keyword = opKeyword($site, $silo, 'sump pump maintenance cost');

    $reactive = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Candidate,
        'matched_silo_id' => $silo->id, 'relevance_score' => 0.95, 'source_name' => 'Patch',
    ]);
    $directed = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Candidate,
        'matched_silo_id' => $silo->id, 'target_keyword_id' => $keyword->id, 'relevance_score' => 0.10,
    ]);

    $cards = app(BlogBoard::class)->candidates($site->id);

    expect($cards[0]['id'])->toBe($directed->id)               // directed outranks higher-scored reactive
        ->and($cards[0]['keyword'])->toBe('sump pump maintenance cost')
        ->and($cards[0]['target_page'])->toBe('Sump Pumps Guide')
        ->and($cards[1]['id'])->toBe($reactive->id)
        ->and($cards[1]['source'])->toBe('Patch');
});

it('promote queues the existing generate path; dismiss records a rejection', function () {
    Queue::fake();
    $site = opSite();
    session(['operate_blog_site' => null]);
    $candidate = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Candidate, 'title' => 'Storm season prep']);
    $other = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Candidate]);

    Livewire::test(OperateBlog::class)
        ->call('promote', $candidate->id)
        ->call('dismissCandidate', $other->id);

    Queue::assertPushed(GeneratePost::class);
    expect($other->fresh()->status)->toBe(ContentStatus::Rejected)
        ->and($other->fresh()->reject_reason)->toBe('Dismissed at candidate triage');
});

it('one-click Approve runs the existing approve + publish path; Reject records the typed reason', function () {
    Queue::fake();
    $site = opSite();
    $draft = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::NeedsReview,
        'title' => 'Why basements flood in spring', 'body' => 'A real drafted body.',
    ]);
    $bad = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::NeedsReview, 'body' => 'Off-brand.',
    ]);

    $page = Livewire::test(OperateBlog::class, ['tab' => 'review'])
        ->call('approve', $draft->id);

    expect($draft->fresh()->status)->not->toBe(ContentStatus::NeedsReview);
    Queue::assertPushed(PublishContent::class);

    $page->call('startReject', $bad->id)
        ->set('rejectReason', 'Tone is off for this tenant')
        ->call('reject', $bad->id);
    expect($bad->fresh()->status)->toBe(ContentStatus::Rejected)
        ->and($bad->fresh()->reject_reason)->toBe('Tone is off for this tenant');
});

it('published groups by consumed keyword → pillar page, bare targets first, reactive under Freshness', function () {
    $site = opSite();
    $silo = opSilo($site);

    // A consumed target: published article linked via blog_targets.article_ref (the reactive-lane
    // linkage — the grouping must NOT rely on Content.target_keyword_id alone).
    $article = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'matched_silo_id' => $silo->id, 'title' => 'Pedestal vs submersible, explained',
        'slug' => 'pedestal-vs-submersible', 'published_at' => now()->subDays(3), 'body' => 'x',
    ]);
    BlogTarget::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id,
        'keyword_id' => opKeyword($site, $silo, 'pedestal vs submersible sump pump')->id,
        'status' => BlogTargetStatus::Published, 'article_ref' => $article->id, 'queued_at' => now()->subDays(10),
    ]);
    // A bare queued target — the gap that must surface FIRST.
    BlogTarget::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id,
        'keyword_id' => opKeyword($site, $silo, 'sump pump battery lifespan')->id,
        'status' => BlogTargetStatus::Queued, 'queued_at' => now()->subDay(),
    ]);
    // A reactive article (no consumed keyword) → the silo Freshness bucket.
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'matched_silo_id' => $silo->id, 'title' => 'County storm alert: what to check',
        'slug' => 'county-storm-alert', 'published_at' => now()->subDay(), 'body' => 'x',
    ]);

    $groups = app(BlogBoard::class)->published($site->id);

    expect($groups)->toHaveCount(3)
        // Bare target (0 articles) sorts before both 1-article groups.
        ->and($groups[0]['keyword'])->toBe('sump pump battery lifespan')
        ->and($groups[0]['articles'])->toBe([]);

    $consumed = collect($groups)->first(fn ($g) => $g['keyword'] === 'pedestal vs submersible sump pump');
    expect($consumed['target_page'])->toBe('Sump Pumps Guide')
        ->and($consumed['target_url'])->toBe('https://spg.example/sump-pumps')
        ->and($consumed['articles'][0]['title'])->toBe('Pedestal vs submersible, explained')
        ->and($consumed['articles'][0]['url'])->toBe('https://spg.example/pedestal-vs-submersible');

    $freshness = collect($groups)->first(fn ($g) => $g['kind'] === 'freshness');
    expect($freshness['silo'])->toBe('Sump Pumps')
        ->and($freshness['articles'][0]['title'])->toBe('County storm alert: what to check');
});

it('site + silo filters persist across the three tabs and across visits (session)', function () {
    $site = opSite();
    $other = opSite('OtherCo');
    $silo = opSilo($site);

    $page = Livewire::test(OperateBlog::class)
        ->set('siteFilter', $site->id)
        ->set('siloFilter', $silo->id)
        ->call('setTab', 'review')
        ->call('setTab', 'published');

    // Same component instance ⇒ the filters ride along; the tab switch never clears them.
    expect($page->get('siteFilter'))->toBe($site->id)
        ->and($page->get('siloFilter'))->toBe($silo->id)
        ->and($page->get('tab'))->toBe('published');

    // A fresh visit resumes the sticky site filter from the session.
    expect(session('operate_blog_site'))->toBe($site->id);
    Livewire::test(OperateBlog::class)->assertSet('siteFilter', $site->id);
});

it('the targets drawer lists the unconsumed queue and dismiss flips the status', function () {
    $site = opSite();
    $silo = opSilo($site);
    $target = BlogTarget::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id,
        'keyword_id' => opKeyword($site, $silo, 'french drain cost')->id,
        'status' => BlogTargetStatus::Queued, 'queued_at' => now(),
    ]);

    Livewire::test(OperateBlog::class)
        ->call('toggleTargets')
        ->assertSee('french drain cost')
        ->call('dismissTarget', $target->id);

    expect($target->fresh()->status)->toBe(BlogTargetStatus::Dismissed);
});
