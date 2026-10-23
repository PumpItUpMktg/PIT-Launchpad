<?php

use App\ContentEngine\Drafting\VoiceResolver;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\LinkProspectKind;
use App\Enums\LinkProspectStatus;
use App\Enums\PageType;
use App\Links\OutreachKitDrafter;
use App\Models\Content;
use App\Models\Directory;
use App\Models\LinkProspect;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\OutreachKit;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use Tests\Support\FakeClaudeClient;

function kitSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://sumppumpgurus.com']);
    $office = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hoboken']);
    LocationNapProfile::factory()->create(['site_id' => $site->id, 'location_id' => $office->id, 'business_name' => 'Sump Pump Gurus | Hoboken', 'address_1' => '50 Harrison St', 'city' => 'Hoboken', 'state' => 'NJ', 'postal' => '07030', 'phone_primary' => '(201) 555-0100', 'categories' => ['Plumber', 'Waterproofing Company'], 'description_short' => null, 'description_long' => null]);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Published, 'slug' => 'hoboken-nj', 'location_id' => $office->id]);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Battery backup sump pump installation', 'is_most_profitable' => true]);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump pump repair']);

    return [$site, $office];
}

function kitModel(string $json): OutreachKitDrafter
{
    return new OutreachKitDrafter(new FakeClaudeClient($json), app(VoiceResolver::class));
}

it('drafts a directory listing kit: the NAP and link verbatim from our data, the copy from the model, kept for the next click', function () {
    [$site, $office] = kitSite();
    $yelp = Directory::factory()->create(['domain' => 'yelp.com', 'name' => 'Yelp', 'submission_url' => 'https://biz.yelp.com/signup']);
    $drafter = kitModel('Here you go: {"short_description":"Sump pump installation and repair in Hoboken.","long_description":"Sump Pump Gurus installs and repairs sump pumps and battery backups for Hoboken homeowners. Same-day service.","categories":["Plumber","Waterproofing"],"pitch_subject":"Listing: Sump Pump Gurus | Hoboken","pitch_body":"Family-run sump pump specialists serving Hoboken."}');

    $kit = $drafter->forDirectory($office, $yelp);
    $k = $kit->payload;

    expect($kit->target_kind)->toBe(OutreachKit::DIRECTORY)
        ->and($kit->model_ok)->toBeTrue()
        ->and($k['nap']['business_name'])->toBe('Sump Pump Gurus | Hoboken')
        ->and($k['nap']['phone'])->toBe('(201) 555-0100')
        ->and($k['link_url'])->toBe('https://sumppumpgurus.com/hoboken-nj/')   // the office page, trailing-slash form
        ->and($k['short_description'])->toBe('Sump pump installation and repair in Hoboken.')
        ->and($k['categories'])->toBe(['Plumber', 'Waterproofing'])
        ->and($k['target']['submission_url'])->toBe('https://biz.yelp.com/signup');

    // A second click returns the kept kit without another model call; redraft replaces it in place.
    $again = kitModel('{"short_description":"NEW","long_description":"NEW LONG","categories":[],"pitch_subject":"s","pitch_body":"b"}');
    expect($again->forDirectory($office, $yelp)->payload['short_description'])->toBe('Sump pump installation and repair in Hoboken.')
        ->and($again->forDirectory($office, $yelp, redraft: true)->payload['short_description'])->toBe('NEW')
        ->and(OutreachKit::withoutGlobalScope(SiteScope::class)->count())->toBe(1);
});

it('falls back to plain copy written from the NAP when the model answers badly, and says so', function () {
    [$site, $office] = kitSite();
    $dir = Directory::factory()->create(['domain' => 'hotfrog.com', 'name' => 'Hotfrog']);

    $kit = kitModel('Sorry, I cannot help with that.')->forDirectory($office, $dir);
    $k = $kit->payload;

    expect($kit->model_ok)->toBeFalse()
        ->and($k['short_description'])->toContain('Sump Pump Gurus | Hoboken')
        ->and($k['short_description'])->toContain('Battery backup sump pump installation')
        ->and($k['long_description'])->toContain('(201) 555-0100')
        ->and($k['categories'])->toBe(['Plumber', 'Waterproofing Company'])
        ->and($k['pitch_body'])->toContain('50 Harrison St');
});

it('drafts a prospect pitch for an office or for the brand, each kept separately', function () {
    [$site, $office] = kitSite();
    $prospect = LinkProspect::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'domain' => 'hudson-chamber.org', 'rank' => 40, 'competitors' => ['rivalplumbing.com'], 'competitor_count' => 1, 'kind' => LinkProspectKind::Local, 'status' => LinkProspectStatus::New, 'found_at' => now()]);
    $drafter = kitModel('{"short_description":"Hoboken sump pump specialists.","long_description":"Long.","categories":[],"pitch_subject":"A Hoboken member for your directory","pitch_body":"Hello, we are Sump Pump Gurus in Hoboken. Would you add https://sumppumpgurus.com/hoboken-nj/ to your members page?"}');

    $forOffice = $drafter->forProspect($prospect, $office);
    $forBrand = $drafter->forProspect($prospect, null);

    expect($forOffice->target_kind)->toBe(OutreachKit::PROSPECT)
        ->and($forOffice->location_id)->toBe((string) $office->id)
        ->and($forOffice->payload['link_url'])->toBe('https://sumppumpgurus.com/hoboken-nj/')
        ->and($forOffice->payload['pitch_subject'])->toBe('A Hoboken member for your directory')
        ->and($forOffice->payload['target']['competitors'])->toBe(['rivalplumbing.com'])
        ->and($forBrand->location_id)->toBeNull()
        ->and($forBrand->payload['link_url'])->toBe('https://sumppumpgurus.com/')
        ->and($forBrand->payload['nap']['business_name'])->toBe('Sump Pump Gurus')
        ->and(OutreachKit::withoutGlobalScope(SiteScope::class)->count())->toBe(2);
});

it('the company kit speaks from the corporate address — a state-level body is approached once by the business, never per office', function () {
    [$site] = kitSite();
    $site->forceFill(['corporate_street' => '10 Main St', 'corporate_city' => 'Clifton', 'corporate_state' => 'NJ', 'corporate_postal_code' => '07011', 'phone' => '(973) 555-0100'])->save();
    $prospect = LinkProspect::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'domain' => 'njbuilders.org', 'competitors' => [], 'competitor_count' => 0, 'kind' => LinkProspectKind::Membership, 'status' => LinkProspectStatus::New, 'found_at' => now(), 'offices' => ['NJ'], 'location_id' => null]);
    $kit = kitModel('{"short_description":"s","long_description":"l","categories":[],"pitch_subject":"Joining the association","pitch_body":"Hello"}')->forProspect($prospect, null);

    expect($kit->payload['nap'])->toMatchArray(['business_name' => 'Sump Pump Gurus', 'address' => '10 Main St', 'city' => 'Clifton', 'state' => 'NJ', 'postal' => '07011', 'phone' => '(973) 555-0100'])
        ->and($kit->payload['link_url'])->toBe('https://sumppumpgurus.com/');
});
