<?php

use App\Enums\ContentStatus;
use App\Enums\ReviewFlag;
use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Overview;
use App\Filament\Pages\SiteCockpit;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Support\Ui\ChipTone;
use App\Support\Ui\StateChip;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

// ── The single chip vocabulary (App\Support\Ui\StateChip) ────────────────────────────────────

it('resolves every content status to a non-empty label and a tone', function () {
    foreach (ContentStatus::cases() as $status) {
        $chip = StateChip::resolve($status);
        expect($chip['label'])->toBeString()->not->toBe('')
            ->and($chip['tone'])->toBeInstanceOf(ChipTone::class);
    }
});

it('resolves every site status and every review flag to a tone', function () {
    foreach (SiteStatus::cases() as $status) {
        expect(StateChip::resolve($status)['tone'])->toBeInstanceOf(ChipTone::class);
    }
    foreach (ReviewFlag::cases() as $flag) {
        $chip = StateChip::resolve($flag);
        expect($chip['tone'])->toBeInstanceOf(ChipTone::class)
            ->and($chip['label'])->toBe($flag->label()); // the flag owns its wording
    }
});

it('maps the semantics correctly — settled=good, human-needed=warn, failure=bad', function () {
    expect(StateChip::resolve(ContentStatus::Published)['tone'])->toBe(ChipTone::Good)
        ->and(StateChip::resolve(ContentStatus::Approved)['tone'])->toBe(ChipTone::Good)
        ->and(StateChip::resolve(SiteStatus::Live)['tone'])->toBe(ChipTone::Good)
        ->and(StateChip::resolve(ContentStatus::NeedsReview)['tone'])->toBe(ChipTone::Warn)
        ->and(StateChip::resolve(SiteStatus::Onboarding)['tone'])->toBe(ChipTone::Warn)
        ->and(StateChip::resolve(ContentStatus::RenderFailed)['tone'])->toBe(ChipTone::Bad)
        ->and(StateChip::resolve(ContentStatus::PublishFailed)['tone'])->toBe(ChipTone::Bad)
        ->and(StateChip::resolve(SiteStatus::Suspended)['tone'])->toBe(ChipTone::Bad);
});

it('accepts a raw status string and degrades unknown values to a neutral humanized chip', function () {
    expect(StateChip::resolve('published')['tone'])->toBe(ChipTone::Good)
        ->and(StateChip::resolve('needs_review')['label'])->toBe('Needs review');

    $unknown = StateChip::resolve('some_made_up_state');
    expect($unknown['tone'])->toBe(ChipTone::Neutral)
        ->and($unknown['label'])->toBe('Some made up state'); // never a crash, never an unstyled leak
});

// ── The chip component ───────────────────────────────────────────────────────────────────────

it('the chip component renders the one class vocabulary', function () {
    $html = Blade::render('<x-lp.chip :for="$s" />', ['s' => ContentStatus::RenderFailed]);
    expect($html)->toContain('lp-chip')->toContain('lp-chip--bad')->toContain('Render failed');

    $explicit = Blade::render('<x-lp.chip tone="good" label="Live" />');
    expect($explicit)->toContain('lp-chip--good')->toContain('Live');
});

// ── The standard page header ─────────────────────────────────────────────────────────────────

it('the page header renders the eyebrow + title and the site-scope indicator reads the active tenant', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    app(ActiveTenant::class)->set($site->id);

    $html = Blade::render('<x-lp.page-header eyebrow="Per-site" title="Cockpit" />');
    expect($html)->toContain('Per-site')->toContain('Cockpit')
        ->toContain('lp-scope')
        ->toContain('Sump Pump Gurus'); // same session tenant as the topbar switcher
});

it('the page header omits the scope indicator when scope is disabled (portfolio-wide pages)', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $site = Site::factory()->create(['brand_name' => 'Basement Guard']);
    app(ActiveTenant::class)->set($site->id);

    $html = Blade::render('<x-lp.page-header title="What needs you" :scope="false" />');
    expect($html)->toContain('What needs you')
        ->not->toContain('lp-scope')
        ->not->toContain('Basement Guard');
});

// ── The standard empty state ─────────────────────────────────────────────────────────────────

it('the empty state names a next action and links it — never a dead end', function () {
    $html = Blade::render(
        '<x-lp.empty title="No content yet" action="Go to Blog" href="/admin/blog">Generate posts.</x-lp.empty>'
    );
    expect($html)->toContain('lp-empty')
        ->toContain('No content yet')
        ->toContain('Generate posts.')
        ->toContain('Go to Blog')
        ->toContain('href="/admin/blog"');
});

// ── The three shells ─────────────────────────────────────────────────────────────────────────
// The shell wraps a Filament page (needs a Livewire page context), so it's exercised through the
// real pages that adopt it rather than rendered standalone.

it('the Portfolio page is framed by the board shell + the standard header', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    Livewire::test(Overview::class)
        ->assertOk()
        ->assertSee('lp-shell--board', false) // the board layout frame
        ->assertSee('What needs you');        // the standard page header
});

it('the Site Cockpit is framed by the board shell and shows its status chip', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $site = Site::factory()->create(['brand_name' => 'Drain Kings']);
    session(['cockpit_site_id' => $site->id]);

    Livewire::test(SiteCockpit::class)
        ->assertOk()
        ->assertSee('lp-shell--board', false)
        ->assertSee('lp-chip', false)   // the one chip vocabulary, not a per-page pill
        ->assertSee('Drain Kings');
});
