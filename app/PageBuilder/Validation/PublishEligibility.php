<?php

namespace App\PageBuilder\Validation;

use App\Enums\ContentStatus;
use App\Models\Content;
use InvalidArgumentException;

/**
 * Orchestrates publish-eligibility for a Content page: validates its slot
 * payload against the pinned kit version, applies the thin-page guard, and on
 * any failure parks the page in review (never live) with structured reasons.
 */
class PublishEligibility
{
    public function __construct(
        private readonly KitValidator $validator,
        private readonly ThinPageGuard $guard,
    ) {}

    public function evaluate(Content $content, ValidationContext $context): ValidationResult
    {
        $kit = $content->wireframeKit;

        if ($kit === null) {
            throw new InvalidArgumentException("Content [{$content->id}] has no wireframe kit to validate against.");
        }

        $schema = $kit->schema();
        $payload = $content->slot_payload ?? [];

        $result = $this->validator->validate($schema, $payload, $context);

        $thin = $this->guard->evaluate($schema, $context);
        if (! $thin->earned) {
            $result = $result->merge(ValidationResult::fail([
                new ValidationFailure(null, ValidationCode::ThinPage, 'Page has no entity-backed proof yet — not earned.'),
            ]));
        }

        if ($result->failed()) {
            $content->update(['status' => ContentStatus::InReview]);
        }

        return $result;
    }
}
