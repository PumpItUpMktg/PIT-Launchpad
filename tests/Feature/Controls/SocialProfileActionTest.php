<?php

use App\Enums\UserRole;
use App\Filament\Resources\ConnectionsResource;
use App\Filament\Resources\ConnectionsResource\Pages\ListConnections;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Social\ProfilePicker;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('services.upload_post.api_key', 'agency-key');   // the form shows only with the agency key
});

function fakeAccountProfiles(): void
{
    Http::fake([
        'api.upload-post.com/api/uploadposts/users' => Http::response(['success' => true, 'profiles' => [
            ['username' => 'SumpPumpGurus', 'facebook_page_name' => 'Sump Pump Gurus Corporate',
                'social_accounts' => ['google_business' => ['display_name' => 'Eric Wright'], 'facebook' => ['display_name' => 'Eric'], 'instagram' => ['username' => 'sumppumpgurus']]],
            ['username' => 'SPG-Pennsylvania', 'social_accounts' => ['google_business' => ['display_name' => 'Sump Pump Gurus'], 'facebook' => '', 'instagram' => '']],
            ['username' => 'OtherClient', 'social_accounts' => ['facebook' => ['display_name' => 'Other Co'], 'instagram' => '']],
        ]]),
    ]);
}

test('the picker lists the account\'s profiles with what is connected, greys out one another tenant owns, and saves the tick as the site\'s profiles', function () {
    fakeAccountProfiles();
    Site::factory()->create(['brand_name' => 'Other Co', 'social_profile' => 'OtherClient']);
    $site = Site::factory()->create(['brand_name' => 'SPG', 'social_profile' => 'SumpPumpGurus, Gone-Profile']);
    app(ActiveTenant::class)->set($site->id);

    $pick = app(ProfilePicker::class)->forSite($site);
    expect(array_keys($pick['options']))->toBe(['OtherClient', 'SPG-Pennsylvania', 'SumpPumpGurus', 'Gone-Profile'])
        ->and($pick['descriptions']['SumpPumpGurus'])->toBe('Google Business Profile: Eric Wright · Facebook: Sump Pump Gurus Corporate · Instagram: sumppumpgurus')
        ->and($pick['descriptions']['OtherClient'])->toBe('Facebook: Other Co · used by Other Co')
        ->and($pick['descriptions']['Gone-Profile'])->toBe('not in the provider account any more')
        ->and($pick['taken'])->toBe(['OtherClient' => 'Other Co'])
        ->and($pick['current'])->toBe(['SumpPumpGurus', 'Gone-Profile'])
        ->and($pick['error'])->toBeNull();

    // Opening the modal pre-ticks the site's current profiles.
    Livewire::test(ListConnections::class)->mountAction('socialProfile')
        ->assertSchemaStateSet(['social_profiles' => ['SumpPumpGurus', 'Gone-Profile']]);

    Livewire::test(ListConnections::class)->callAction('socialProfile', ['social_profiles' => ['SumpPumpGurus', 'SPG-Pennsylvania']]);
    expect($site->fresh()->social_profile)->toBe('SumpPumpGurus, SPG-Pennsylvania');

    // A profile another tenant owns never lands on this site, whatever the request carried.
    expect(ProfilePicker::normalise(['SumpPumpGurus', 'OtherClient', ' SumpPumpGurus '], $pick['taken']))->toBe(['SumpPumpGurus']);

    Livewire::test(ListConnections::class)->callAction('socialProfile', ['social_profiles' => []]);
    expect($site->fresh()->social_profile)->toBeNull();
});

test('when the profile list cannot be read the action falls back to typing the name', function () {
    Http::fake(['api.upload-post.com/api/uploadposts/users' => Http::response(['error' => 'down'], 503)]);
    $site = Site::factory()->create(['brand_name' => 'SPG', 'social_profile' => null]);
    app(ActiveTenant::class)->set($site->id);

    $pick = app(ProfilePicker::class)->forSite($site);
    expect($pick['options'])->toBe([])->and($pick['error'])->not->toBeNull();

    $site->forceFill(['social_profile' => 'typed-one'])->save();
    Livewire::test(ListConnections::class)->mountAction('socialProfile')->assertSchemaStateSet(['social_profile' => 'typed-one']);

    Livewire::test(ListConnections::class)->callAction('socialProfile', ['social_profile' => ' sump-pump-gurus ']);
    expect($site->fresh()->social_profile)->toBe('sump-pump-gurus');

    Livewire::test(ListConnections::class)->callAction('socialProfile', ['social_profile' => '']);
    expect($site->fresh()->social_profile)->toBeNull();
});

test('without the agency key the action explains the setup instead of offering a form', function () {
    config()->set('services.upload_post.api_key', null);
    $site = Site::factory()->create(['social_profile' => null]);
    app(ActiveTenant::class)->set($site->id);

    $action = ConnectionsResource::socialProfileAction();
    expect($action->isDisabled())->toBeFalse()
        ->and((string) $action->getModalHeading())->toBe('Social posting is not connected yet')
        ->and((string) $action->getModalDescription())->toContain('UPLOAD_POST_API_KEY');
    Livewire::test(ListConnections::class)
        ->assertActionEnabled('socialProfile')
        ->callAction('socialProfile');
    expect($site->fresh()->social_profile)->toBeNull();
});
