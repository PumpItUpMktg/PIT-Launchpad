<?php

use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateLocationPages;
use App\Models\GoogleAccount;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

it('submits the sitemap to Google from the board header action', function () {
    GoogleAccount::create([
        'credentials' => ['access_token' => 'tok', 'refresh_token' => 'r', 'expires_at' => (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM)],
        'status' => 'connected',
    ]);
    Site::factory()->create(['brand_name' => 'SPG', 'gsc_property' => 'sc-domain:spg.example', 'domain_url' => 'https://spg.example']);

    Http::fake([
        '*/sitemaps/*' => Http::response('', 200),
        '*/sitemaps' => Http::response(['sitemap' => [['contents' => [['submitted' => 142]]]]]),
    ]);

    Livewire::test(OperateLocationPages::class)
        ->callAction('submitSitemap')
        ->assertNotified('Sitemap submitted to Google');
});

it('warns instead of calling Google when Search Console is not connected', function () {
    Site::factory()->create(['brand_name' => 'SPG', 'gsc_property' => null, 'domain_url' => 'https://spg.example']);
    Http::fake();

    Livewire::test(OperateLocationPages::class)
        ->callAction('submitSitemap')
        ->assertNotified('Could not submit sitemap');

    Http::assertNothingSent();
});
