<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\ConversionConfig;
use App\Models\LeadForm;
use App\Models\Location;
use App\Models\PageConfig;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Publishing\Forms\LeadFormResolver;

beforeEach(function (): void {
    $this->site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $this->general = LeadForm::factory()->create(['site_id' => $this->site->id, 'name' => 'General booking', 'embed' => '<iframe src="https://gurus.joby.io/book?embed=1"></iframe>']);
    $this->montclair = LeadForm::factory()->create(['site_id' => $this->site->id, 'name' => 'Montclair office', 'embed' => '<iframe src="https://gurus.joby.io/montclair?embed=1"></iframe>']);
    $this->install = LeadForm::factory()->create(['site_id' => $this->site->id, 'name' => 'Installation quote', 'embed' => '<iframe src="https://gurus.joby.io/install?embed=1"></iframe>']);
    ConversionConfig::withoutGlobalScope(SiteScope::class)->create(['site_id' => $this->site->id, 'default_lead_form_id' => $this->general->id, 'form_embed' => '<iframe src="https://legacy.example/paste"></iframe>']);
    $this->office = Location::factory()->create(['site_id' => $this->site->id, 'name' => 'Montclair', 'lead_form_id' => $this->montclair->id]);
    $this->service = Service::factory()->create(['site_id' => $this->site->id, 'name' => 'Sump Pump Installation', 'lead_form_id' => $this->install->id]);
});

function lfPage(Site $site, PageType $type, array $attrs = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => $type, 'status' => ContentStatus::Published, 'slug' => fake()->unique()->slug(2),
    ], $attrs));
}

test('a service page takes its service\'s form; the office hub and every town it serves take the office\'s; home takes the site default', function (): void {
    $resolver = new LeadFormResolver;

    $service = lfPage($this->site, PageType::Service, ['primary_service_id' => $this->service->id]);
    $hub = lfPage($this->site, PageType::Location, ['location_id' => $this->office->id]);
    $town = lfPage($this->site, PageType::Location, ['parent_location_id' => $this->office->id, 'slug' => 'montclair-nj/glen-ridge-nj']);
    $home = lfPage($this->site, PageType::Home);

    expect($resolver->resolve($service))->toMatchArray(['source' => 'service', 'form' => 'Installation quote'])
        ->and($resolver->embedFor($service))->toContain('joby.io/install')
        ->and($resolver->resolve($hub))->toMatchArray(['source' => 'location', 'form' => 'Montclair office'])
        ->and($resolver->resolve($town))->toMatchArray(['source' => 'location', 'form' => 'Montclair office'])
        ->and($resolver->embedFor($town))->toContain('joby.io/montclair')
        ->and($resolver->resolve($home))->toMatchArray(['source' => 'default', 'form' => 'General booking']);
});

test('an owner with no form falls to the site default, the default falls to the legacy paste, a page override beats everything', function (): void {
    $resolver = new LeadFormResolver;
    $plainOffice = Location::factory()->create(['site_id' => $this->site->id, 'name' => 'Hoboken']);
    $town = lfPage($this->site, PageType::Location, ['parent_location_id' => $plainOffice->id]);
    expect($resolver->resolve($town)['source'])->toBe('default');

    // No default picked → the pre-library paste still applies, so nothing changes until forms are assigned.
    ConversionConfig::withoutGlobalScope(SiteScope::class)->where('site_id', $this->site->id)->update(['default_lead_form_id' => null]);
    expect($resolver->resolve($town))->toMatchArray(['source' => 'paste', 'form' => null])
        ->and($resolver->embedFor($town))->toContain('legacy.example');

    // Nothing at all → no form.
    ConversionConfig::withoutGlobalScope(SiteScope::class)->where('site_id', $this->site->id)->update(['form_embed' => null]);
    expect($resolver->embedFor($town))->toBeNull();

    // A per-page paste wins over the office's form.
    $hub = lfPage($this->site, PageType::Location, ['location_id' => $this->office->id]);
    PageConfig::create(['site_id' => $this->site->id, 'content_id' => $hub->id, 'form_embed' => '<iframe src="https://one-off.example"></iframe>']);
    expect($resolver->resolve($hub))->toMatchArray(['source' => 'page'])
        ->and($resolver->embedFor($hub))->toContain('one-off.example');
});

test('a form from another site is never resolved, even when an owner points at it', function (): void {
    $otherSite = Site::factory()->create();
    $foreign = LeadForm::factory()->create(['site_id' => $otherSite->id, 'name' => 'Foreign']);
    $office = Location::factory()->create(['site_id' => $this->site->id, 'lead_form_id' => $foreign->id]);
    $hub = lfPage($this->site, PageType::Location, ['location_id' => $office->id]);

    expect((new LeadFormResolver)->resolve($hub)['source'])->toBe('default'); // falls through, never the foreign embed
});
