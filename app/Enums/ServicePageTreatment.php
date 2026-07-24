<?php

namespace App\Enums;

/**
 * How a SUB-service is treated when the operator groups it under a parent service (the services-entry
 * grouping): its own page (a spoke URL under the parent hub) or a section folded into the parent's page.
 *
 * Only meaningful on a child service (one with a `parent_service_id`); a top-level service is implicitly
 * a page. Default is `Section` — sub-services fold in unless the operator deliberately promotes them,
 * so the common outcome is a rich single page rather than a thin extra URL. Maps 1:1 to
 * {@see SpokeGranularity}: `Page` → `OwnPage`, `Section` → `Folded`.
 */
enum ServicePageTreatment: string
{
    case Page = 'page';
    case Section = 'section';

    public function label(): string
    {
        return match ($this) {
            self::Page => 'Its own page',
            self::Section => 'A section on the parent page',
        };
    }

    /** The blueprint granularity this treatment maps to when the structure is written. */
    public function granularity(): SpokeGranularity
    {
        return match ($this) {
            self::Page => SpokeGranularity::OwnPage,
            self::Section => SpokeGranularity::Folded,
        };
    }
}
