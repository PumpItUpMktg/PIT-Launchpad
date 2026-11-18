<?php

use App\Enums\UserRole;
use App\Filament\Resources\ConnectionsResource;
use App\Filament\Resources\ConnectionsResource\Pages\ListConnections;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('services.upload_post.api_key', 'agency-key');   // the form shows only with the agency key
});

test('the social profile action points the locked tenant at its Upload-Post profile, and clears it when blank', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'social_profile' => null]);
    app(ActiveTenant::class)->set($site->id);

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
