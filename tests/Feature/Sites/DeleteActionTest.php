<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Models\Account;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('exposes the Delete site action', function () {
    Livewire::test(ListSites::class)->assertTableActionExists('delete');
});

it('hides the Delete action for a live (handed-over) tenant', function () {
    $live = Site::factory()->create(['status' => SiteStatus::Live]);
    $onboarding = Site::factory()->create(['status' => SiteStatus::Onboarding]);

    Livewire::test(ListSites::class)
        ->assertTableActionHidden('delete', $live)
        ->assertTableActionVisible('delete', $onboarding);
});

it('deletes the tenant and cascades its data, leaving WordPress alone by default', function () {
    $site = Site::factory()->create(['brand_name' => 'Doomed Co']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'wp_post_id' => 99]);
    Silo::factory()->create(['site_id' => $site->id]);

    Livewire::test(ListSites::class)
        ->callTableAction('delete', $site, data: ['purge_wordpress' => false, 'with_account' => false])
        ->assertHasNoTableActionErrors();

    expect(Site::query()->whereKey($site->id)->count())->toBe(0)
        ->and(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0)
        ->and(Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});

it('deletes the owning account when with_account is set and no other sites remain', function () {
    $account = Account::factory()->create();
    $solo = Site::factory()->create(['account_id' => $account->id]);

    Livewire::test(ListSites::class)
        ->callTableAction('delete', $solo, data: ['purge_wordpress' => false, 'with_account' => true]);

    expect(Account::query()->whereKey($account->id)->count())->toBe(0);
});

it('keeps the account when it still has other sites', function () {
    $account = Account::factory()->create();
    $one = Site::factory()->create(['account_id' => $account->id]);
    $two = Site::factory()->create(['account_id' => $account->id]);

    Livewire::test(ListSites::class)
        ->callTableAction('delete', $one, data: ['purge_wordpress' => false, 'with_account' => true]);

    expect(Account::query()->whereKey($account->id)->count())->toBe(1)
        ->and(Site::query()->whereKey($two->id)->count())->toBe(1);
});
