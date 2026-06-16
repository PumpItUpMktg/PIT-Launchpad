<?php

use App\Branding\BrandGenerator;
use App\Branding\BrandStudio;
use App\Branding\FontCatalog;
use App\Branding\IndustryResolver;
use App\Branding\Scheme;
use App\Enums\ConnectionProvider;
use App\Enums\ServiceSiloRole;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Connection;
use App\Models\Service;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Publishing\BrandKitAssembler;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeClaudeClient;

const BRAND_JSON = '{"palette":{"primary":"#0F62FE","accent":"#FF6F00","text":"#1A1A1A"},"typography":{"heading":"Montserrat","body":"Inter"},"rationale":"Confident and reliable."}';

/**
 * @return array{0: BrandStudio, 1: FakeClaudeClient}
 */
function brandStudio(string $json = BRAND_JSON): array
{
    $fake = new FakeClaudeClient($json);
    $studio = new BrandStudio(
        new BrandGenerator($fake, new FontCatalog),
        new IndustryResolver,
        app(BrandKitAssembler::class),
        app(WordpressClientFactory::class),
    );

    return [$studio, $fake];
}

it('pre-fills the industry from the service catalog', function () {
    $site = Site::factory()->create();
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Roof Repair', 'silo_role' => ServiceSiloRole::Pillar]);

    [$studio] = brandStudio();

    expect($studio->industryFor($site))->toBe('Roof Repair');
});

it('generates a brand and uses the operator-supplied industry in the prompt', function () {
    $site = Site::factory()->create();
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Roof Repair', 'silo_role' => ServiceSiloRole::Pillar]);

    [$studio, $fake] = brandStudio();
    $brand = $studio->generate($site, [
        'industry' => 'luxury roofing',
        'personality' => 'premium',
        'emotional_goal' => 'confidence',
        'color_anchors_avoid' => 'neon, pink',
    ]);

    expect($brand->typography)->toBe(['heading' => 'Montserrat', 'body' => 'Inter'])
        ->and($fake->prompts[0])->toContain('luxury roofing')   // operator value wins
        ->and($fake->prompts[0])->toContain('Premium & refined')
        ->and($fake->prompts[0])->toContain('Avoid these colors: neon, pink');
});

it('falls back to the derived industry when the operator leaves it blank', function () {
    $site = Site::factory()->create();
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Roof Repair', 'silo_role' => ServiceSiloRole::Pillar]);

    [$studio, $fake] = brandStudio();
    $studio->generate($site, ['industry' => '', 'personality' => 'trustworthy']);

    expect($fake->prompts[0])->toContain('Roof Repair');
});

it('saves the reviewed palette + typography and preserves other branding fields', function () {
    $site = Site::factory()->create();
    SiteBranding::withoutGlobalScopes()->create([
        'site_id' => $site->id,
        'entity_type' => 'Plumber',
        'logo_set' => ['primary' => 'logo.png'],
        'palette' => ['primary' => '#000000'],
    ]);

    [$studio] = brandStudio();
    $studio->save($site, ['primary' => '#0f62fe', 'accent' => '#ff6f00', 'text' => '#1a1a1a'], ['heading' => 'Montserrat', 'body' => 'Inter']);

    $branding = SiteBranding::withoutGlobalScopes()->where('site_id', $site->id)->firstOrFail();

    expect($branding->palette)->toBe(['primary' => '#0f62fe', 'accent' => '#ff6f00', 'text' => '#1a1a1a'])
        ->and($branding->typography)->toBe(['heading' => 'Montserrat', 'body' => 'Inter'])
        ->and($branding->entity_type)->toBe('Plumber')           // preserved
        ->and($branding->logo_set)->toBe(['primary' => 'logo.png']); // preserved
});

it('pushes the saved brand into the Elementor Global Kit (reusing the #105 path)', function () {
    Http::fake(['*/wp-json/launchpad/v1/brand-kit' => Http::response(
        ['updated' => true, 'kit_id' => 8, 'colors_set' => 3, 'fonts_set' => 2],
    )]);

    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://apex.example', 'username' => 'u', 'app_password' => 'pw'],
    ]);

    [$studio] = brandStudio();
    $studio->save($site, ['primary' => '#0f62fe', 'accent' => '#ff6f00', 'text' => '#1a1a1a'], ['heading' => 'Montserrat', 'body' => 'Inter']);
    $result = $studio->push($site);

    expect($result['updated'])->toBeTrue()->and($result['kit_id'])->toBe(8);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/wp-json/launchpad/v1/brand-kit')
        && $r['colors']['primary'] === '#0f62fe');
});

it('returns a non-fatal result when there is no brand to push', function () {
    $site = Site::factory()->create();

    [$studio] = brandStudio();
    $result = $studio->push($site);

    expect($result['updated'])->toBeFalse()->and($result['error'])->toContain('No brand captured');
});

const CANDIDATES_JSON = '{"candidates":[{"tokens":{"--wf-color-primary":"#1B3A5B","--wf-color-secondary":"#3E6E9E","--wf-color-accent":"#B25C00","--wf-color-text":"#1A1A1A","--wf-color-text-muted":"#5B6470","--wf-color-bg":"#FFFFFF","--wf-color-bg-alt":"#F4F6F8","--wf-color-border":"#E2E6EB"},"fonts":{"--wf-font-heading":"Archivo","--wf-font-body":"Inter"},"rationale":"Navy and amber for plumbing.","recommended":true},{"tokens":{"--wf-color-primary":"#1B3A5B","--wf-color-secondary":"#3E6E9E","--wf-color-accent":"#B25C00","--wf-color-text":"#1A1A1A","--wf-color-text-muted":"#5B6470","--wf-color-bg":"#FFFFFF","--wf-color-bg-alt":"#F4F6F8","--wf-color-border":"#E2E6EB"},"fonts":{"--wf-font-heading":"Sora","--wf-font-body":"Inter"},"rationale":"Alternate.","recommended":false}]}';

it('generates a candidate set for a pinned structure', function () {
    [$studio] = brandStudio(CANDIDATES_JSON);

    $set = $studio->generateCandidates(['industry' => 'plumbing', 'personality' => 'trustworthy'], structure: 'bold');

    expect($set->structure)->toBe('bold')
        ->and($set->candidates)->toHaveCount(2)
        ->and($set->recommended()->typography['heading'])->toBe('Archivo');
});

it('recommends a structure (model pick) and uses it when none is pinned', function () {
    [$studio] = brandStudio('{"structure":"warm","rationale":"Friendly and local."}');

    expect($studio->recommendStructure(['industry' => 'hvac', 'personality' => 'friendly-local'])->slug)->toBe('warm');
});

it('persists the chosen structure preset on save', function () {
    $site = Site::factory()->create();

    [$studio] = brandStudio();
    $studio->save($site, ['primary' => '#0f62fe'], ['heading' => 'Inter', 'body' => 'Inter'], 'bold');

    expect(SiteBranding::withoutGlobalScopes()->where('site_id', $site->id)->firstOrFail()->structure_preset)->toBe('bold');
});

it('recommends a curated palette by id from the library (closed set)', function () {
    [$studio] = brandStudio('{"id":"carbon","rationale":"Modern, technical, high-contrast."}');

    $rec = $studio->recommendPalette(['industry' => 'auto detailing', 'personality' => 'modern-technical'], Scheme::Dark);

    expect($rec->palette->id)->toBe('carbon')
        ->and($rec->palette->scheme)->toBe(Scheme::Dark)
        ->and($rec->rationale)->toBe('Modern, technical, high-contrast.');
});

it('falls back to the scheme default when the model returns an off-list id', function () {
    [$studio] = brandStudio('{"id":"not-a-palette"}');

    expect($studio->recommendPalette(['industry' => 'plumbing', 'personality' => 'trustworthy'], Scheme::Dark)->palette->id)
        ->toBe('midnight-current'); // the dark default
});

it('lists the whole scheme library as candidates with the recommended one flagged', function () {
    [$studio] = brandStudio('{"id":"carbon","rationale":"x"}');

    $set = $studio->paletteCandidates(['industry' => 'auto', 'personality' => 'modern-technical'], Scheme::Dark);

    expect($set->scheme)->toBe(Scheme::Dark)
        ->and($set->structure)->toBe('bold')                       // carbon's form affinity
        ->and($set->candidates)->toHaveCount(3)                     // all dark seeds
        ->and(collect($set->candidates)->where('recommended', true))->toHaveCount(1)
        ->and($set->recommended()->palette['primary'])->toBe('#818cf8'); // carbon
});

it('threads the personality adjectives into the generation prompt', function () {
    $site = Site::factory()->create();

    [$studio, $fake] = brandStudio();
    $studio->generate($site, ['industry' => 'roofing', 'personality' => 'trustworthy', 'adjectives' => ['rugged', 'established']]);

    expect($fake->prompts[0])->toContain('Rugged')->toContain('Established');
});
