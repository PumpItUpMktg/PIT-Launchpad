<?php

namespace App\PageBuilder\Validation;

use App\PageBuilder\Entities\EntityResolver;
use App\PageBuilder\Schema\KitSchema;

/**
 * Publish-eligibility guard: if a page's proof slots resolve to zero entity
 * content, it has not "earned" publication — light generated copy alone does
 * not earn a page. Generalized, but exercised hardest by the location kit
 * (market-tagged reviews + Job Capture work).
 */
class ThinPageGuard
{
    public function __construct(private readonly EntityResolver $entities) {}

    public function evaluate(KitSchema $kit, ValidationContext $context): ThinPageResult
    {
        $count = 0;

        foreach ($kit->slots as $slot) {
            if (! $slot->appliesTo($context->flags)) {
                continue;
            }

            if (! $slot->isProof() || $slot->source->value !== 'entity' || $slot->constraints->entity === null) {
                continue;
            }

            $count += $this->entities->count($slot->constraints->entity, $context) ?? 0;
        }

        return new ThinPageResult($count > 0, $count);
    }
}
