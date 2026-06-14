<?php

use App\Enums\ConnectionProvider;
use App\Models\Connection;
use App\Models\Site;
use App\Models\SiteBranding;
use App\PageBuilder\Template\KitTemplateArtifacts;
use Illuminate\Support\Facades\Http;

function pushSite(): Site
{
    $site = Site::factory()->create(['domain_url' => 'https://apex.example', 'brand_name' => 'Acme']);
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://apex.example', 'username' => 'launchpad-sync', 'app_password' => 'pw'],
    ]);

    return $site;
}

function tempArtifactDir(): string
{
    $dir = sys_get_temp_dir().'/lp-kit-artifacts-'.uniqid();
    mkdir($dir, 0777, true);
    file_put_contents(
        $dir.'/service-page.native.elementor.json',
        (string) json_encode(['version' => '0.4', 'title' => 'Single Page – Service', 'type' => 'section', 'content' => [['widgetType' => 'heading']]]),
    );

    return $dir;
}

beforeEach(function () {
    // Bind the artifact resolver to a deterministic temp dir so the test does not
    // depend on the repo's committed artifacts.
    app()->bind(KitTemplateArtifacts::class, fn () => new KitTemplateArtifacts(tempArtifactDir()));
});

it('pushes the brand kit first (one-pass provisioning) when branding is captured', function () {
    Http::fake([
        '*/wp-json/launchpad/v1/brand-kit' => Http::response(['updated' => true, 'kit_id' => 7, 'colors_set' => 1, 'fonts_set' => 0]),
        '*/wp-json/launchpad/v1/kit-template' => Http::response(['kit' => 'service-page', 'template_id' => 91, 'created' => true, 'condition_set' => false, 'pro' => false, 'condition' => []]),
    ]);

    $site = pushSite();
    SiteBranding::withoutGlobalScopes()->create(['site_id' => $site->id, 'palette' => ['primary' => '#0F62FE']]);

    $this->artisan('launchpad:push-kit-template', ['site' => $site->id, '--kit' => 'service-page'])
        ->expectsOutputToContain('brand: applied')
        ->assertSuccessful();

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/wp-json/launchpad/v1/brand-kit') && $r['colors']['primary'] === '#0F62FE');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/wp-json/launchpad/v1/kit-template'));
});

it('skips the brand push with --skip-brand', function () {
    Http::fake(['*/wp-json/launchpad/v1/kit-template' => Http::response(['kit' => 'service-page', 'template_id' => 91, 'created' => true, 'condition_set' => false, 'pro' => false, 'condition' => []])]);

    $site = pushSite();
    SiteBranding::withoutGlobalScopes()->create(['site_id' => $site->id, 'palette' => ['primary' => '#0F62FE']]);

    $this->artisan('launchpad:push-kit-template', ['site' => $site->id, '--kit' => 'service-page', '--skip-brand' => true])
        ->assertSuccessful();

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/wp-json/launchpad/v1/brand-kit'));
});

it('pushes a kit artifact to /kit-template and reports the result', function () {
    Http::fake(['*/wp-json/launchpad/v1/kit-template' => Http::response([
        'kit' => 'service-page', 'template_id' => 91, 'created' => true, 'condition_set' => false, 'pro' => false,
        'condition' => ['rule' => 'include/singular/in_lp_kit/5'],
    ])]);

    $this->artisan('launchpad:push-kit-template', ['site' => pushSite()->id, '--kit' => 'service-page'])
        ->expectsOutputToContain('created template #91')
        ->assertSuccessful();

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/wp-json/launchpad/v1/kit-template')
        && $r->method() === 'POST'
        && $r['kit'] === 'service-page'
        && $r['template']['content'][0]['widgetType'] === 'heading');
});

it('warns to set the condition by hand when Pro is absent', function () {
    Http::fake(['*/wp-json/launchpad/v1/kit-template' => Http::response([
        'kit' => 'service-page', 'template_id' => 91, 'created' => false, 'condition_set' => false, 'pro' => false,
        'condition' => ['rule' => 'include/singular/in_lp_kit/5'],
    ])]);

    $this->artisan('launchpad:push-kit-template', ['site' => pushSite()->id, '--kit' => 'service-page'])
        ->expectsOutputToContain('Elementor Pro not active')
        ->assertSuccessful();
});

it('discovers all kits with an artifact when no --kit is given', function () {
    Http::fake(['*/wp-json/launchpad/v1/kit-template' => Http::response([
        'kit' => 'service-page', 'template_id' => 91, 'created' => true, 'condition_set' => true, 'pro' => true, 'condition' => [],
    ])]);

    $this->artisan('launchpad:push-kit-template', ['site' => pushSite()->id])
        ->expectsOutputToContain('Display Condition set')
        ->assertSuccessful();

    Http::assertSent(fn ($r) => $r['kit'] === 'service-page');
});

it('fails when a kit has no artifact', function () {
    Http::fake();

    $this->artisan('launchpad:push-kit-template', ['site' => pushSite()->id, '--kit' => 'no-such-kit'])
        ->expectsOutputToContain('no artifact')
        ->assertFailed();
});

it('fails when the site has no WordPress connection', function () {
    Http::fake();

    $this->artisan('launchpad:push-kit-template', ['site' => Site::factory()->create()->id, '--kit' => 'service-page'])
        ->assertFailed();
    Http::assertNothingSent();
});

it('fails cleanly when the site is not found', function () {
    $this->artisan('launchpad:push-kit-template', ['site' => '01NOTASITE0000000000000000', '--kit' => 'service-page'])
        ->expectsOutputToContain('Site not found')
        ->assertFailed();
});
