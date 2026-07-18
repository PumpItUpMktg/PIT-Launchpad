<?php

use App\Enums\UserRole;
use App\Filament\Pages\Gathering\ConnectionsStep;
use App\Models\ConversionConfig;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * New Setup step 6 — Connections & Feeds: the operator pastes the site's embedded
 * lead-form embed here (one paste, site-wide). It persists to ConversionConfig.ghl_form_embed,
 * which already flows onto every service page's conversion block.
 */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_setup_enabled', true);
    $this->site = Site::factory()->create();
    session(['guided_site_id' => $this->site->id]);
});

function siteEmbed(Site $site): ?string
{
    return ConversionConfig::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $site->id)
        ->value('form_embed');
}

it('saves the pasted form embed to the site conversion config (upsert, site-wide)', function () {
    $embed = '<iframe src="https://api.leadconnectorhq.com/widget/form/abc"></iframe><script src="https://link.msgsndr.com/js/form_embed.js"></script>';

    Livewire::test(ConnectionsStep::class)
        ->assertOk()
        ->assertSee('Lead-capture form')
        ->set('formEmbed', $embed)
        ->call('saveLeadForm')
        ->assertNotified()
        ->assertSet('formEmbed', $embed);

    expect(siteEmbed($this->site))->toBe($embed);
});

it('loads an existing embed on mount and reports it on file', function () {
    $embed = '<iframe src="https://api.leadconnectorhq.com/widget/form/xyz"></iframe>';
    ConversionConfig::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $this->site->id,
        'form_embed' => $embed,
    ]);

    Livewire::test(ConnectionsStep::class)
        ->assertOk()
        ->assertSet('formEmbed', $embed)
        ->assertSee('on file');
});

it('clears the embed to null when saved blank (call-button-only floor)', function () {
    ConversionConfig::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $this->site->id,
        'form_embed' => '<iframe src="x"></iframe>',
    ]);

    Livewire::test(ConnectionsStep::class)
        ->set('formEmbed', '   ')
        ->call('saveLeadForm')
        ->assertSet('formEmbed', null);

    expect(siteEmbed($this->site))->toBeNull();
});

it('persists the embed when advancing with Save & continue', function () {
    $embed = '<iframe src="https://api.leadconnectorhq.com/widget/form/cont"></iframe>';

    $page = Livewire::test(ConnectionsStep::class);
    expect($page->instance()->savesOnContinue())->toBeTrue();

    $page->set('formEmbed', $embed)
        ->call('continueToNext');

    expect(siteEmbed($this->site))->toBe($embed);
});
