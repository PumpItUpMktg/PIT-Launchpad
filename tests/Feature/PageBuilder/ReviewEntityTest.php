<?php

use App\Enums\ProofType;
use App\Models\Content;
use App\Models\Market;
use App\Models\ProofItem;
use App\Models\Site;
use App\PageBuilder\Entities\EntityResolver;
use App\PageBuilder\Validation\ValidationContext;
use Tests\Support\PageBuilder;

function resolverContext(Content $content): ValidationContext
{
    return new ValidationContext($content);
}

it('reviews.site counts every substantiated review for the site (any market or none)', function () {
    $site = Site::factory()->create();
    $market = Market::factory()->create(['site_id' => $site->id]);
    $tagged = ProofItem::factory()->create(['site_id' => $site->id, 'type' => ProofType::Testimonial, 'is_substantiated' => true]);
    $tagged->markets()->attach($market->id);
    ProofItem::factory()->create(['site_id' => $site->id, 'type' => ProofType::Testimonial, 'is_substantiated' => true]); // site-wide
    ProofItem::factory()->create(['site_id' => $site->id, 'type' => ProofType::Testimonial, 'is_substantiated' => false]); // not substantiated
    $content = Content::factory()->create(['site_id' => $site->id]);

    expect(app(EntityResolver::class)->count('reviews.site', resolverContext($content)))->toBe(2);
});

it('reviews.market counts the page market plus site-wide reviews, not other markets', function () {
    $site = Site::factory()->create();
    $market = Market::factory()->create(['site_id' => $site->id]);
    $other = Market::factory()->create(['site_id' => $site->id]);

    $forMarket = ProofItem::factory()->create(['site_id' => $site->id, 'type' => ProofType::Testimonial, 'is_substantiated' => true]);
    $forMarket->markets()->attach($market->id);
    ProofItem::factory()->create(['site_id' => $site->id, 'type' => ProofType::Testimonial, 'is_substantiated' => true]); // site-wide → counts
    $forOther = ProofItem::factory()->create(['site_id' => $site->id, 'type' => ProofType::Testimonial, 'is_substantiated' => true]);
    $forOther->markets()->attach($other->id); // a different market → does NOT count

    $content = Content::factory()->create(['site_id' => $site->id, 'market_id' => $market->id]);

    expect(app(EntityResolver::class)->count('reviews.market', resolverContext($content)))->toBe(2);
});

it('locks the gate-redesign kit shapes', function () {
    // Hub+spoke relay: the service kit dropped its entity PROOF slots (testimonial/proof_strip) —
    // reviews are provider-gated page SECTIONS now. Only the two platform CONVERSION slots remain
    // entity-sourced (cta / contact_block), each condition-gated on real §1 data.
    $service = PageBuilder::serviceKit();
    expect(collect($service->slots)->firstWhere('key', 'testimonial'))->toBeNull()
        ->and(collect($service->slots)->firstWhere('key', 'proof_strip'))->toBeNull();

    $cta = collect($service->slots)->firstWhere('key', 'cta');
    expect($cta->source->value)->toBe('entity')
        ->and($cta->condition->field)->toBe('has_location_phone');

    $contact = collect($service->slots)->firstWhere('key', 'contact_block');
    expect($contact->source->value)->toBe('entity')
        ->and($contact->condition->field)->toBe('has_location');

    // The block-era location kit dropped its entity review slot: local reviews are a provider-gated
    // page SECTION (empty ⇒ omitted by the composer), never a required kit slot.
    $location = PageBuilder::locationKit();
    expect(collect($location->slots)->firstWhere('key', 'local_testimonials'))->toBeNull()
        ->and(collect($location->slots)->filter(fn ($s) => $s->constraints->entity !== null)->all())->toBe([]);
});
