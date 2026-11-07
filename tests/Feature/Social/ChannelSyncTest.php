<?php

use App\Enums\SocialNetwork;
use App\Models\Location;
use App\Models\Site;
use App\Models\SocialChannel;
use App\Social\ChannelSync;
use App\Social\Provider\MockSocialProvider;
use App\Social\Provider\SocialProfile;
use App\Social\Provider\SocialProvider;

it('creates channels from the provider\'s profiles — GBP tied to a location by place id, address, or name; the Pages at site level; an unplaceable GBP stays unmatched', function () {
    $site = Site::factory()->create();
    $hack = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office', 'place_id' => 'ChIJ-hack', 'address' => '12 Main St, Hackettstown, NJ 07840']);
    $phil = Location::factory()->create(['site_id' => $site->id, 'name' => 'Phillipsburg', 'place_id' => null, 'address' => '400 Memorial Pkwy, Phillipsburg, NJ 08865']);
    $wash = Location::factory()->create(['site_id' => $site->id, 'name' => 'Washington', 'place_id' => null, 'address' => null]);
    /** @var MockSocialProvider $mock */
    $mock = app(SocialProvider::class);
    $mock->connect($site->id, [
        new SocialProfile('g1', SocialNetwork::Gbp, 'Sump Pump Gurus - Hackettstown', '12 Main Street, Hackettstown', 'ChIJ-hack'),
        new SocialProfile('g2', SocialNetwork::Gbp, 'Sump Pump Gurus', '400 Memorial Parkway, Phillipsburg NJ'),
        new SocialProfile('g3', SocialNetwork::Gbp, 'Sump Pump Gurus Washington NJ'),
        new SocialProfile('g4', SocialNetwork::Gbp, 'Sump Pump Gurus - Somewhere Else', '1 Nowhere Rd'),
        new SocialProfile('fb', SocialNetwork::Facebook, 'Sump Pump Gurus'),
        new SocialProfile('ig', SocialNetwork::Instagram, '@sumppumpgurus'),
    ]);

    $r = app(ChannelSync::class)->sync($site);

    $by = SocialChannel::withoutGlobalScopes()->where('site_id', $site->id)->get()->keyBy('provider_channel_id');
    expect($r)->toMatchArray(['synced' => 6, 'matched' => 3, 'unmatched' => ['Sump Pump Gurus - Somewhere Else'], 'disabled' => 0])
        ->and($by['g1']->location_id)->toBe($hack->id)
        ->and($by['g2']->location_id)->toBe($phil->id)
        ->and($by['g3']->location_id)->toBe($wash->id)
        ->and($by['g4']->location_id)->toBeNull()->and($by['g4']->unmatched())->toBeTrue()
        ->and($by['fb']->location_id)->toBeNull()->and($by['fb']->unmatched())->toBeFalse()
        ->and($by['ig']->network)->toBe(SocialNetwork::Instagram)
        ->and($by['g1']->provider_name)->toBe('Sump Pump Gurus - Hackettstown')
        ->and($by['g1']->synced_at)->not->toBeNull();

    // A profile that vanished from the provider is disabled, its row (and history) kept; a re-sync re-matches.
    $mock->connect($site->id, [new SocialProfile('g1', SocialNetwork::Gbp, 'Sump Pump Gurus - Hackettstown', null, 'ChIJ-hack')]);
    $r2 = app(ChannelSync::class)->sync($site);
    expect($r2['disabled'])->toBe(5)
        ->and(SocialChannel::withoutGlobalScopes()->where('provider_channel_id', 'fb')->value('enabled'))->toBeFalse()
        ->and(SocialChannel::withoutGlobalScopes()->where('provider_channel_id', 'g1')->value('enabled'))->toBeTrue();
});

it('a single-location site takes any GBP profile as that location', function () {
    $site = Site::factory()->create();
    $only = Location::factory()->create(['site_id' => $site->id, 'name' => 'HQ']);
    /** @var MockSocialProvider $mock */
    $mock = app(SocialProvider::class);
    $mock->connect($site->id, [new SocialProfile('g9', SocialNetwork::Gbp, 'Some Business Name')]);

    app(ChannelSync::class)->sync($site);

    expect(SocialChannel::withoutGlobalScopes()->where('provider_channel_id', 'g9')->value('location_id'))->toBe($only->id);
});
