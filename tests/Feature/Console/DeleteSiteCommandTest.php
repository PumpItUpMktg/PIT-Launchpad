<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\SiteStatus;
use App\Models\Account;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function seedSiteData(Site $site): void
{
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service]);
    Silo::factory()->create(['site_id' => $site->id]);
    Service::factory()->create(['site_id' => $site->id]);
    Keyword::factory()->create(['site_id' => $site->id]);
}

function siteRows(string $siteId): int
{
    return Site::query()->whereKey($siteId)->count();
}

it('permanently deletes the site and cascades all its data', function () {
    $site = Site::factory()->create(['brand_name' => 'Doomed Co']);
    seedSiteData($site);

    $this->artisan('launchpad:delete-site', ['site' => $site->id, '--force' => true])
        ->expectsOutputToContain("Deleted 'Doomed Co'")
        ->assertSuccessful();

    expect(siteRows($site->id))->toBe(0)
        ->and(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0)
        ->and(Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0)
        ->and(Service::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0)
        ->and(Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});

it('clears raw site_id tables that have no cascade FK', function () {
    $site = Site::factory()->create();
    DB::table('page_configs')->insert([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'content_id' => (string) Str::ulid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('launchpad:delete-site', ['site' => $site->id, '--force' => true])->assertSuccessful();

    expect(DB::table('page_configs')->where('site_id', $site->id)->count())->toBe(0);
});

it('dry-run changes nothing', function () {
    $site = Site::factory()->create(['brand_name' => 'Keep Me']);
    seedSiteData($site);

    $this->artisan('launchpad:delete-site', ['site' => $site->id, '--dry-run' => true])
        ->expectsOutputToContain('[dry-run] would delete')
        ->assertSuccessful();

    expect(siteRows($site->id))->toBe(1)
        ->and(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1);
});

it('refuses an ambiguous brand_name and lists the candidate ids', function () {
    $a = Site::factory()->create(['brand_name' => 'Sewer Gurus']);
    $b = Site::factory()->create(['brand_name' => 'Sewer Gurus']);

    $this->artisan('launchpad:delete-site', ['site' => 'Sewer Gurus', '--force' => true])
        ->expectsOutputToContain('Ambiguous brand_name')
        ->assertFailed();

    expect(siteRows($a->id))->toBe(1)->and(siteRows($b->id))->toBe(1); // nothing deleted
});

it('refuses a live (handed-over) tenant', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Live]);

    $this->artisan('launchpad:delete-site', ['site' => $site->id, '--force' => true])
        ->expectsOutputToContain('it is LIVE')
        ->assertFailed();

    expect(siteRows($site->id))->toBe(1);
});

it('leaves WordPress untouched by default', function () {
    $site = Site::factory()->create();
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'wp_post_id' => 123]);

    // No Http fake / WP connection needed — default path must not call WordPress at all.
    $this->artisan('launchpad:delete-site', ['site' => $site->id, '--force' => true])
        ->expectsOutputToContain('WordPress: left untouched')
        ->assertSuccessful();

    expect(siteRows($site->id))->toBe(0);
});

it('deletes the owning account only with --with-account and when no other sites remain', function () {
    $account = Account::factory()->create();
    $solo = Site::factory()->create(['account_id' => $account->id]);

    $this->artisan('launchpad:delete-site', ['site' => $solo->id, '--with-account' => true, '--force' => true])
        ->assertSuccessful();

    expect(Account::query()->whereKey($account->id)->count())->toBe(0);
});

it('keeps the account when it still has other sites', function () {
    $account = Account::factory()->create();
    $one = Site::factory()->create(['account_id' => $account->id]);
    $two = Site::factory()->create(['account_id' => $account->id]);

    $this->artisan('launchpad:delete-site', ['site' => $one->id, '--with-account' => true, '--force' => true])
        ->assertSuccessful();

    expect(Account::query()->whereKey($account->id)->count())->toBe(1)
        ->and(siteRows($two->id))->toBe(1);
});
