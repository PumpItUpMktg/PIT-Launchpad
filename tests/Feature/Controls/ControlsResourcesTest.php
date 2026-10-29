<?php

use App\Enums\UserRole;
use App\Filament\Resources\LeadFormResource;
use App\Filament\Resources\LeadFormResource\Pages\ListLeadForms;
use App\Filament\Resources\SourceResource\Pages\ListSources;
use App\Filament\Resources\VoiceProfileResource\Pages\ListVoiceProfiles;
use App\Models\LeadForm;
use App\Models\Location;
use App\Models\Service;
use App\Models\Site;
use App\Models\Source;
use App\Models\User;
use App\Models\VoiceProfile;
use Filament\Facades\Filament;
use Livewire\Livewire;

test('the feeds and voice controls render for an operator', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create();
    Source::factory()->create(['site_id' => $site->id]);
    VoiceProfile::factory()->create(['site_id' => $site->id]);

    Livewire::test(ListSources::class)->assertOk();
    Livewire::test(ListVoiceProfiles::class)->assertOk();
});

test('the lead-form library renders and says where each form is used', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create();
    $office = LeadForm::factory()->create(['site_id' => $site->id, 'name' => 'Montclair office']);
    $install = LeadForm::factory()->create(['site_id' => $site->id, 'name' => 'Installation quote']);
    $unused = LeadForm::factory()->create(['site_id' => $site->id, 'name' => 'Spare']);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair', 'lead_form_id' => $office->id]);
    Service::factory()->create(['site_id' => $site->id, 'lead_form_id' => $install->id]);
    Service::factory()->create(['site_id' => $site->id, 'lead_form_id' => $install->id]);

    expect(LeadFormResource::usedBy($office))->toBe('Montclair')
        ->and(LeadFormResource::usedBy($install))->toBe('2 services')
        ->and(LeadFormResource::usedBy($unused))->toBe('not assigned yet');

    Livewire::test(ListLeadForms::class)->assertOk()->assertSee('Montclair office')->assertSee('2 services');
});
