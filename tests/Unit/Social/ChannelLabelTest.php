<?php

use App\Social\ChannelLabel;

it('abbreviates a multi-word brand to its initials and keeps a one-word brand', function () {
    expect(ChannelLabel::brandAbbreviation('Sump Pump Gurus'))->toBe('SPG')
        ->and(ChannelLabel::brandAbbreviation('Sump Pump Gurus, Inc.'))->toBe('SPGI')
        ->and(ChannelLabel::brandAbbreviation('Roto'))->toBe('Roto');
});

it('names an office by its town', function () {
    expect(ChannelLabel::officeTown('Trooper office'))->toBe('Trooper')
        ->and(ChannelLabel::officeTown('Clifton corporate office'))->toBe('Clifton corporate')
        ->and(ChannelLabel::officeTown('Main Office'))->toBe('Main Office');
});

it('builds the GBP pill: initials + office town, Google\'s town kept when it disagrees, another brand\'s title kept whole', function () {
    $brand = 'Sump Pump Gurus';
    expect(ChannelLabel::gbpPill('Sump Pump Gurus | Trooper', $brand, 'Trooper office'))->toBe('SPG · Trooper')
        ->and(ChannelLabel::gbpPill('Sump Pump Gurus', $brand, 'Montclair office'))->toBe('SPG · Montclair')
        ->and(ChannelLabel::gbpPill('Sump Pump Gurus | Spring City', $brand, 'Phoenixville office'))->toBe('SPG | Spring City → Phoenixville')
        ->and(ChannelLabel::gbpPill('Sump Pump Gurus | Hoboken', $brand, null))->toBe('SPG | Hoboken')
        ->and(ChannelLabel::gbpPill('Sump Pump Gurus', $brand, null))->toBe('SPG')
        ->and(ChannelLabel::gbpPill('Sump Pump Today | Pottstown', $brand, null))->toBe('Sump Pump Today | Pottstown')
        ->and(ChannelLabel::gbpPill('Sump Pump Today | Pottstown', $brand, 'Pottstown office'))->toBe('Sump Pump Today | Pottstown → Pottstown')
        ->and(ChannelLabel::listingTown('sump pump gurus - Bedminster', $brand))->toBe('Bedminster');
});
