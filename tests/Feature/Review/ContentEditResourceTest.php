<?php

use App\ContentEngine\Review\EditCapture;
use App\Enums\EditReason;
use App\Enums\UserRole;
use App\Filament\Resources\ContentEditResource;
use App\Models\Content;
use App\Models\User;

it('the edit-signal resource is operator-only', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    expect(ContentEditResource::canAccess())->toBeTrue();

    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(ContentEditResource::canAccess())->toBeFalse();
});

it('the navigation badge tallies captured corrections (the admin tick)', function () {
    expect(ContentEditResource::getNavigationBadge())->toBeNull(); // nothing captured yet

    $content = Content::factory()->page()->create();
    (new EditCapture)->record($content, 'body', 'a', 'b', EditReason::OffBase);
    (new EditCapture)->record($content, 'slot:x', 'c', 'd', EditReason::Preference);

    expect(ContentEditResource::getNavigationBadge())->toBe('2');
});
