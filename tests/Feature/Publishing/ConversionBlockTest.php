<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\ConversionConfig;
use App\Models\Location;
use App\Models\Site;
use App\Models\WireframeKit;
use App\Publishing\MetaBlobAssembler;
use Database\Seeders\WireframeKitSeeder;
use Illuminate\Support\Collection;

/**
 * §3a/§2 dual-conversion block: the service-page `cta` is platform-DERIVED — a
 * "Call Now" tel: link from the primary location's phone plus, when configured,
 * the site's embedded form — and `contact_block` resolves the location NAP. Both
 * overwrite any model copy; both omit gracefully when their floor is absent.
 */
function serviceBlobSlots(Site $site): array
{
    (new WireframeKitSeeder)->run();
    $kit = WireframeKit::query()->where('page_type', 'service')->whereNull('site_id')->firstOrFail();

    $content = Content::factory()->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Service,
        'status' => ContentStatus::Approved,
        'title' => 'Sump Pump Installation',
        'slug' => 'sump-pump-installation',
        'wireframe_kit_id' => $kit->id,
        'wireframe_kit_version' => 1,
        // A stale model-emitted cta label — must be overwritten by the derived block.
        'slot_payload' => ['hero_problem' => 'x', 'cta' => ['label' => 'stale model cta'], 'why_us' => 'Generic why-us copy.'],
        'meta' => ['seo' => ['title' => 'Sump Pump Installation', 'meta_description' => 'Fast sump pump installation.']],
    ]);

    return app(MetaBlobAssembler::class)->assemble($content->fresh(), new Collection)['slot_payload'];
}

it('derives the dual conversion block: tel from the location phone + the embedded form', function () {
    $site = Site::factory()->create();
    Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Trooper', 'address' => '1 Main St', 'phone' => '+15125550142',
    ]);
    ConversionConfig::factory()->create(['site_id' => $site->id, 'form_embed' => '<iframe src="https://ghl/form"></iframe>']);

    $slots = serviceBlobSlots($site);

    expect($slots['cta'])->toMatchArray([
        'type' => 'conversion_block',
        'call_label' => 'Call Now',
        'phone' => '+15125550142',
        'tel' => 'tel:+15125550142',
        'form_embed' => '<iframe src="https://ghl/form"></iframe>',
    ])
        ->and($slots['contact_block'])->toMatchArray([
            'type' => 'nap', 'name' => 'Trooper', 'address' => '1 Main St', 'phone' => '+15125550142',
        ]);
});

it('renders call-button-only when no form is configured (graceful)', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'phone' => '+15125550142']);
    // no ConversionConfig → no form embed

    $slots = serviceBlobSlots($site);

    expect($slots['cta']['tel'])->toBe('tel:+15125550142')
        ->and($slots['cta'])->not->toHaveKey('form_embed'); // omitted, not blocked
});

it('omits cta and contact_block when the tenant has no location', function () {
    $site = Site::factory()->create(); // no location → no derivable phone, no NAP

    $slots = serviceBlobSlots($site);

    expect($slots)->not->toHaveKey('cta')
        ->and($slots)->not->toHaveKey('contact_block');
});

it('a v1 leftover slot (why_us) rides the blob untouched — conditional-drop applies only to DECLARED slots', function () {
    // Hub+spoke relay: why_us left the service kit (the block composer gates proof sections
    // itself), so a v1-drafted page's stored why_us is off-schema — the blob passes it through
    // harmlessly (the plugin renders only bound tags). The conditional machinery is still locked
    // by the cta/contact_block tests above.
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'phone' => '+15125550142']);

    $slots = serviceBlobSlots($site);

    expect($slots)->toHaveKey('why_us');
});
