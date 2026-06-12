<?php

use App\Enums\ConnectionProvider;
use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Resources\PublishedContentResource;
use App\Filament\Resources\PublishedContentResource\Pages\ListPublishedContent;
use App\Jobs\PublishContent;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

function verifiedWpConnection(Site $site): void
{
    Connection::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://x.com', 'username' => 'u', 'app_password' => 'p'],
        'status' => 'active',
        'compromised' => false,
        'last_rotated_at' => now(),
    ]);
}

it('lists only published content, newest first', function () {
    $site = Site::factory()->create();
    Content::factory()->create(['site_id' => $site->id, 'title' => 'Live Page', 'status' => ContentStatus::Published, 'published_at' => now()]);
    Content::factory()->create(['site_id' => $site->id, 'title' => 'Still In Review', 'status' => ContentStatus::NeedsReview]);

    Livewire::test(ListPublishedContent::class)
        ->assertCanSeeTableRecords(Content::withoutGlobalScope(SiteScope::class)->where('status', ContentStatus::Published)->get())
        ->assertSee('Live Page')
        ->assertDontSee('Still In Review');
});

it('queues an idempotent re-push when the site has a verified connection', function () {
    Bus::fake();
    $site = Site::factory()->create();
    verifiedWpConnection($site);
    $content = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'published_at' => now()]);

    Livewire::test(ListPublishedContent::class)
        ->callTableAction('repush', $content)
        ->assertHasNoErrors();

    Bus::assertDispatched(PublishContent::class, fn (PublishContent $job): bool => $job->contentId === $content->id);
});

it('refuses to re-push when there is no verified WordPress connection', function () {
    Bus::fake();
    $site = Site::factory()->create();
    $content = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'published_at' => now()]);

    Livewire::test(ListPublishedContent::class)
        ->callTableAction('repush', $content);

    Bus::assertNotDispatched(PublishContent::class);
});

it('is operator-only', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));

    expect(PublishedContentResource::canAccess())->toBeFalse();
});
