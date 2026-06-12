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
    $service = PageBuilder::serviceKit();
    $testimonial = collect($service->slots)->firstWhere('key', 'testimonial');
    expect($testimonial->constraints->entity)->toBe('reviews.site')          // off reviews.market
        ->and($testimonial->condition)->not->toBeNull()                       // conditional
        ->and($testimonial->condition->field)->toBe('has_reviews');

    $proofStrip = collect($service->slots)->firstWhere('key', 'proof_strip');
    expect($proofStrip->constraints->entity)->toBe('proof.substantiated');    // proof stays

    $location = PageBuilder::locationKit();
    $localReviews = collect($location->slots)->firstWhere('key', 'local_testimonials');
    expect($localReviews->constraints->entity)->toBe('reviews.market')        // market-scoped gate
        ->and($localReviews->isRequired())->toBeTrue()
        ->and($localReviews->constraints->minEntities)->toBe(1);
});
