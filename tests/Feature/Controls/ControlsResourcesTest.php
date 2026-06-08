<?php

use App\Enums\UserRole;
use App\Filament\Resources\SourceResource\Pages\ListSources;
use App\Filament\Resources\VoiceProfileResource\Pages\ListVoiceProfiles;
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
